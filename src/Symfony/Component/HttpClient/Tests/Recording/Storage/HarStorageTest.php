<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HttpClient\Tests\Recording\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Recording\HttpRecord;
use Symfony\Component\HttpClient\Recording\Storage\HarStorage;

#[CoversClass(HarStorage::class)]
class HarStorageTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/symfony_har_storage_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testSaveAndLoad()
    {
        $storage = new HarStorage($this->tempDir);

        $entries = [
            new HttpRecord(
                method: 'GET',
                url: 'https://example.com/api/users',
                requestHeaders: ['accept' => ['application/json']],
                requestBody: '',
                statusCode: 200,
                responseHeaders: ['content-type' => ['application/json']],
                responseBody: '{"users":[]}',
                totalTime: 0.5,
                timestamp: 1700000000,
            ),
            new HttpRecord(
                method: 'POST',
                url: 'https://example.com/api/users',
                requestHeaders: ['content-type' => ['application/json']],
                requestBody: '{"name":"John"}',
                statusCode: 201,
                responseHeaders: ['content-type' => ['application/json']],
                responseBody: '{"id":1,"name":"John"}',
                totalTime: 0.3,
                timestamp: 1700000001,
            ),
        ];

        $storage->save('test_cassette', $entries);

        $this->assertTrue($storage->exists('test_cassette'));
        $this->assertFileExists($this->tempDir . '/test_cassette.har');

        $loaded = $storage->load('test_cassette');

        $this->assertCount(2, $loaded);

        $this->assertSame('GET', $loaded[0]->method);
        $this->assertSame('https://example.com/api/users', $loaded[0]->url);
        $this->assertSame(200, $loaded[0]->statusCode);
        $this->assertSame('{"users":[]}', $loaded[0]->responseBody);

        $this->assertSame('POST', $loaded[1]->method);
        $this->assertSame(201, $loaded[1]->statusCode);
        $this->assertSame('{"name":"John"}', $loaded[1]->requestBody);
    }

    public function testLoadNonExistent()
    {
        $storage = new HarStorage($this->tempDir);

        $entries = $storage->load('nonexistent');

        $this->assertSame([], $entries);
    }

    public function testExists()
    {
        $storage = new HarStorage($this->tempDir);

        $this->assertFalse($storage->exists('new_cassette'));

        $storage->save('new_cassette', []);

        $this->assertTrue($storage->exists('new_cassette'));
    }

    public function testDelete()
    {
        $storage = new HarStorage($this->tempDir);

        $storage->save('to_delete', [
            new HttpRecord(
                method: 'GET',
                url: 'https://example.com',
                requestHeaders: [],
                requestBody: '',
                statusCode: 200,
                responseHeaders: [],
                responseBody: 'test',
                totalTime: 0.1,
                timestamp: time(),
            ),
        ]);

        $this->assertTrue($storage->exists('to_delete'));

        $storage->delete('to_delete');

        $this->assertFalse($storage->exists('to_delete'));
    }

    public function testDeleteNonExistent()
    {
        $storage = new HarStorage($this->tempDir);

        // Should not throw
        $storage->delete('nonexistent');

        $this->assertFalse($storage->exists('nonexistent'));
    }

    public function testBinaryContent()
    {
        $storage = new HarStorage($this->tempDir);

        $binaryContent = "\x00\x01\x02\xFF\xFE\xFD";

        $entries = [
            new HttpRecord(
                method: 'GET',
                url: 'https://example.com/binary',
                requestHeaders: [],
                requestBody: '',
                statusCode: 200,
                responseHeaders: ['content-type' => ['application/octet-stream']],
                responseBody: $binaryContent,
                totalTime: 0.1,
                timestamp: time(),
            ),
        ];

        $storage->save('binary_test', $entries);
        $loaded = $storage->load('binary_test');

        $this->assertSame($binaryContent, $loaded[0]->responseBody);
    }

    public function testHarFormat()
    {
        $storage = new HarStorage($this->tempDir);

        $entries = [
            new HttpRecord(
                method: 'GET',
                url: 'https://example.com/api?foo=bar',
                requestHeaders: ['accept' => ['application/json']],
                requestBody: '',
                statusCode: 200,
                responseHeaders: ['content-type' => ['application/json']],
                responseBody: '{"status":"ok"}',
                totalTime: 0.5,
                timestamp: 1700000000,
                info: ['custom_key' => 'custom_value'],
            ),
        ];

        $storage->save('format_test', $entries);

        $harContent = json_decode(file_get_contents($this->tempDir . '/format_test.har'), true);

        // Verify HAR structure
        $this->assertArrayHasKey('log', $harContent);
        $this->assertSame('1.2', $harContent['log']['version']);
        $this->assertArrayHasKey('creator', $harContent['log']);
        $this->assertArrayHasKey('entries', $harContent['log']);

        $entry = $harContent['log']['entries'][0];

        // Verify entry structure
        $this->assertArrayHasKey('startedDateTime', $entry);
        $this->assertArrayHasKey('time', $entry);
        $this->assertArrayHasKey('request', $entry);
        $this->assertArrayHasKey('response', $entry);

        // Verify request
        $this->assertSame('GET', $entry['request']['method']);
        $this->assertSame('https://example.com/api?foo=bar', $entry['request']['url']);

        // Verify response
        $this->assertSame(200, $entry['response']['status']);
        $this->assertSame('{"status":"ok"}', $entry['response']['content']['text']);

        // Verify Symfony extension for custom info
        $this->assertArrayHasKey('_symfony', $entry);
        $this->assertSame('custom_value', $entry['_symfony']['custom_key']);
    }

    public function testQueryStringParsing()
    {
        $storage = new HarStorage($this->tempDir);

        $entries = [
            new HttpRecord(
                method: 'GET',
                url: 'https://example.com/search?q=test&page=1&sort=name',
                requestHeaders: [],
                requestBody: '',
                statusCode: 200,
                responseHeaders: [],
                responseBody: '',
                totalTime: 0.1,
                timestamp: time(),
            ),
        ];

        $storage->save('query_test', $entries);

        $harContent = json_decode(file_get_contents($this->tempDir . '/query_test.har'), true);
        $queryString = $harContent['log']['entries'][0]['request']['queryString'];

        $this->assertCount(3, $queryString);

        $names = array_column($queryString, 'name');
        $this->assertContains('q', $names);
        $this->assertContains('page', $names);
        $this->assertContains('sort', $names);
    }

    public function testCreatesDirectoryIfNotExists()
    {
        $newDir = $this->tempDir . '/nested/path/to/storage';
        $storage = new HarStorage($newDir);

        $entries = [
            new HttpRecord(
                method: 'GET',
                url: 'https://example.com',
                requestHeaders: [],
                requestBody: '',
                statusCode: 200,
                responseHeaders: [],
                responseBody: '',
                totalTime: 0.1,
                timestamp: time(),
            ),
        ];

        $storage->save('nested_test', $entries);

        $this->assertDirectoryExists($newDir);
        $this->assertFileExists($newDir . '/nested_test.har');
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
