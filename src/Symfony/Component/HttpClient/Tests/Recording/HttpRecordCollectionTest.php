<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HttpClient\Tests\Recording;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Recording\HttpRecord;
use Symfony\Component\HttpClient\Recording\HttpRecordCollection;
use Symfony\Component\HttpClient\Recording\RequestMatcher;
use Symfony\Component\HttpClient\Recording\Storage\HarStorage;

#[CoversClass(HttpRecordCollection::class)]
class HttpRecordCollectionTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/symfony_cassette_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testGetName()
    {
        $storage = new HarStorage($this->tempDir);
        $collection = new HttpRecordCollection('my_collection', $storage);

        $this->assertSame('my_collection', $collection->getName());
    }

    public function testRecordAndFind()
    {
        $storage = new HarStorage($this->tempDir);
        $collection = new HttpRecordCollection('test', $storage);
        $matcher = new RequestMatcher();

        $entry = new HttpRecord(
            method: 'GET',
            url: 'https://example.com/api',
            requestHeaders: [],
            requestBody: '',
            statusCode: 200,
            responseHeaders: ['content-type' => ['application/json']],
            responseBody: '{"data":"test"}',
            totalTime: 0.5,
            timestamp: time(),
        );

        $collection->record($entry);

        $found = $collection->find('GET', 'https://example.com/api', [], $matcher);

        $this->assertNotNull($found);
        $this->assertSame('GET', $found->method);
        $this->assertSame('https://example.com/api', $found->url);
        $this->assertSame(200, $found->statusCode);
    }

    public function testFindReturnsNullWhenNotFound()
    {
        $storage = new HarStorage($this->tempDir);
        $collection = new HttpRecordCollection('empty', $storage);
        $matcher = new RequestMatcher();

        $found = $collection->find('GET', 'https://example.com/nonexistent', [], $matcher);

        $this->assertNull($found);
    }

    public function testSaveAndLoad()
    {
        $storage = new HarStorage($this->tempDir);
        $collection = new HttpRecordCollection('persist_test', $storage);
        $matcher = new RequestMatcher();

        $entry = new HttpRecord(
            method: 'POST',
            url: 'https://example.com/create',
            requestHeaders: ['content-type' => ['application/json']],
            requestBody: '{"name":"test"}',
            statusCode: 201,
            responseHeaders: [],
            responseBody: '{"id":1}',
            totalTime: 0.3,
            timestamp: time(),
        );

        $collection->record($entry);
        $collection->save();

        // Create new collection instance to load from storage
        $newCollection = new HttpRecordCollection('persist_test', $storage);
        $found = $newCollection->find('POST', 'https://example.com/create', [], $matcher);

        $this->assertNotNull($found);
        $this->assertSame('POST', $found->method);
        $this->assertSame('{"id":1}', $found->responseBody);
    }

    public function testClear()
    {
        $storage = new HarStorage($this->tempDir);
        $collection = new HttpRecordCollection('clear_test', $storage);
        $matcher = new RequestMatcher();

        $entry = new HttpRecord(
            method: 'GET',
            url: 'https://example.com/data',
            requestHeaders: [],
            requestBody: '',
            statusCode: 200,
            responseHeaders: [],
            responseBody: 'data',
            totalTime: 0.1,
            timestamp: time(),
        );

        $collection->record($entry);
        $this->assertFalse($collection->isEmpty());

        $collection->clear();
        $this->assertTrue($collection->isEmpty());
        $this->assertNull($collection->find('GET', 'https://example.com/data', [], $matcher));
    }

    public function testIsEmpty()
    {
        $storage = new HarStorage($this->tempDir);
        $collection = new HttpRecordCollection('empty_test', $storage);

        $this->assertTrue($collection->isEmpty());

        $collection->record(new HttpRecord(
            method: 'GET',
            url: 'https://example.com',
            requestHeaders: [],
            requestBody: '',
            statusCode: 200,
            responseHeaders: [],
            responseBody: '',
            totalTime: 0.1,
            timestamp: time(),
        ));

        $this->assertFalse($collection->isEmpty());
    }

    public function testGetEntries()
    {
        $storage = new HarStorage($this->tempDir);
        $collection = new HttpRecordCollection('entries_test', $storage);

        $entry1 = new HttpRecord(
            method: 'GET',
            url: 'https://example.com/1',
            requestHeaders: [],
            requestBody: '',
            statusCode: 200,
            responseHeaders: [],
            responseBody: 'one',
            totalTime: 0.1,
            timestamp: time(),
        );

        $entry2 = new HttpRecord(
            method: 'GET',
            url: 'https://example.com/2',
            requestHeaders: [],
            requestBody: '',
            statusCode: 200,
            responseHeaders: [],
            responseBody: 'two',
            totalTime: 0.2,
            timestamp: time(),
        );

        $collection->record($entry1);
        $collection->record($entry2);

        $entries = $collection->getEntries();

        $this->assertCount(2, $entries);
        $this->assertSame('one', $entries[0]->responseBody);
        $this->assertSame('two', $entries[1]->responseBody);
    }

    public function testReplaceOrAdd()
    {
        $storage = new HarStorage($this->tempDir);
        $collection = new HttpRecordCollection('replace_test', $storage);
        $matcher = new RequestMatcher();

        $entry1 = new HttpRecord(
            method: 'GET',
            url: 'https://example.com/api',
            requestHeaders: [],
            requestBody: '',
            statusCode: 200,
            responseHeaders: [],
            responseBody: 'original',
            totalTime: 0.1,
            timestamp: time(),
        );

        $collection->record($entry1);

        $entry2 = new HttpRecord(
            method: 'GET',
            url: 'https://example.com/api',
            requestHeaders: [],
            requestBody: '',
            statusCode: 200,
            responseHeaders: [],
            responseBody: 'replaced',
            totalTime: 0.2,
            timestamp: time(),
        );

        $collection->replaceOrAdd($entry2, $matcher);

        $entries = $collection->getEntries();
        $this->assertCount(1, $entries);
        $this->assertSame('replaced', $entries[0]->responseBody);
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
