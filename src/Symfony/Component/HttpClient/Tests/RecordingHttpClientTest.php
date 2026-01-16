<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HttpClient\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\NoMatchingRecordingException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Recording\HttpRecordCollection;
use Symfony\Component\HttpClient\Recording\RecordingMode;
use Symfony\Component\HttpClient\Recording\RequestMatcher;
use Symfony\Component\HttpClient\Recording\Storage\HarStorage;
use Symfony\Component\HttpClient\RecordingHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(RecordingHttpClient::class)]
class RecordingHttpClientTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/symfony_http_recording_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testRecordMode()
    {
        $mockClient = new MockHttpClient([
            new MockResponse('{"status":"ok"}', [
                'http_code' => 200,
                'response_headers' => ['Content-Type' => 'application/json'],
            ]),
        ]);

        $storage = new HarStorage($this->tempDir);
        $collection = new HttpRecordCollection('test_record', $storage);
        $client = new RecordingHttpClient($mockClient, $collection, RecordingMode::Record);

        $response = $client->request('GET', 'https://api.example.com/status');
        $content = $response->getContent();

        $this->assertSame('{"status":"ok"}', $content);
        $this->assertSame(200, $response->getStatusCode());

        // Verify recording was saved
        $this->assertFileExists($this->tempDir . '/test_record.har');
    }

    public function testPlaybackMode()
    {
        // First, record a response
        $mockClient = new MockHttpClient([
            new MockResponse('recorded response', ['http_code' => 200]),
        ]);

        $storage = new HarStorage($this->tempDir);
        $collection = new HttpRecordCollection('test_playback', $storage);
        $client = new RecordingHttpClient($mockClient, $collection, RecordingMode::Record);

        $response = $client->request('GET', 'https://api.example.com/data');
        $response->getContent();

        // Now switch to playback mode
        $newMockClient = new MockHttpClient([
            new MockResponse('this should not be returned', ['http_code' => 500]),
        ]);

        $newCollection = new HttpRecordCollection('test_playback', $storage);
        $playbackClient = new RecordingHttpClient($newMockClient, $newCollection, RecordingMode::Playback);

        $response = $playbackClient->request('GET', 'https://api.example.com/data');
        $this->assertSame('recorded response', $response->getContent());
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testPlaybackModeThrowsOnUnknownRequest()
    {
        $storage = new HarStorage($this->tempDir);
        $collection = new HttpRecordCollection('empty_cassette', $storage);
        $mockClient = new MockHttpClient();
        $client = new RecordingHttpClient($mockClient, $collection, RecordingMode::Playback);

        $this->expectException(NoMatchingRecordingException::class);
        $this->expectExceptionMessage('No recording found for "GET https://api.example.com/unknown"');

        $response = $client->request('GET', 'https://api.example.com/unknown');
        $response->getContent();
    }

    public function testNewEpisodesMode()
    {
        // First request - should record
        $mockClient = new MockHttpClient([
            new MockResponse('first response', ['http_code' => 200]),
            new MockResponse('second response', ['http_code' => 201]),
        ]);

        $storage = new HarStorage($this->tempDir);
        $collection = new HttpRecordCollection('test_new_episodes', $storage);
        $client = new RecordingHttpClient($mockClient, $collection, RecordingMode::NewEpisodes);

        // First request - records
        $response1 = $client->request('GET', 'https://api.example.com/first');
        $this->assertSame('first response', $response1->getContent());

        // Second request - also records (new endpoint)
        $response2 = $client->request('GET', 'https://api.example.com/second');
        $this->assertSame('second response', $response2->getContent());

        // Now create a new client instance that reads from saved cassette
        $newMockClient = new MockHttpClient([
            new MockResponse('should not see this', ['http_code' => 500]),
        ]);
        $newCollection = new HttpRecordCollection('test_new_episodes', $storage);
        $newClient = new RecordingHttpClient($newMockClient, $newCollection, RecordingMode::NewEpisodes);

        // Should replay from cassette
        $replayResponse = $newClient->request('GET', 'https://api.example.com/first');
        $this->assertSame('first response', $replayResponse->getContent());
    }

    public function testDisabledMode()
    {
        $mockClient = new MockHttpClient([
            new MockResponse('passthrough response', ['http_code' => 200]),
        ]);

        $storage = new HarStorage($this->tempDir);
        $collection = new HttpRecordCollection('test_disabled', $storage);
        $client = new RecordingHttpClient($mockClient, $collection, RecordingMode::Disabled);

        $response = $client->request('GET', 'https://api.example.com/status');
        $this->assertSame('passthrough response', $response->getContent());

        // Cassette should not be created
        $this->assertFileDoesNotExist($this->tempDir . '/test_disabled.har');
    }

    public function testWithMode()
    {
        $mockClient = new MockHttpClient();
        $storage = new HarStorage($this->tempDir);
        $collection = new HttpRecordCollection('test', $storage);
        $client = new RecordingHttpClient($mockClient, $collection, RecordingMode::Record);

        $this->assertSame(RecordingMode::Record, $client->getMode());

        $newClient = $client->withMode(RecordingMode::Playback);

        $this->assertSame(RecordingMode::Playback, $newClient->getMode());
        $this->assertSame(RecordingMode::Record, $client->getMode()); // Original unchanged
    }

    /*
     * @deprecated since Symfony 6.3, use withRecordCollection() instead
     */
    /*
    public function testWithCassette()
    {
        // ... if we want backward compat or just replace it
    }
    */
    // Since I'm refactoring internally, I just update the test to use new methods.

    public function testWithRecordCollection()
    {
        $mockClient = new MockHttpClient();
        $storage = new HarStorage($this->tempDir);
        $collection1 = new HttpRecordCollection('test1', $storage);
        $collection2 = new HttpRecordCollection('test2', $storage);

        $client = new RecordingHttpClient($mockClient, $collection1);
        $newClient = $client->withRecordCollection($collection2);

        $this->assertSame('test1', $client->getRecordCollection()->getName());
        $this->assertSame('test2', $newClient->getRecordCollection()->getName());
    }

    public function testWithMatcher()
    {
        $mockClient = new MockHttpClient([
            new MockResponse('response', ['http_code' => 200]),
        ]);

        $storage = new HarStorage($this->tempDir);
        $collection = new HttpRecordCollection('test_matcher', $storage);

        // Use a matcher that only matches on method (ignores URL)
        $matcher = new RequestMatcher([RequestMatcher::STRATEGY_METHOD]);
        $client = new RecordingHttpClient($mockClient, $collection, RecordingMode::Record, $matcher);

        $response = $client->request('GET', 'https://api.example.com/one');
        $response->getContent();

        // Create a new client and try to match with a different URL but same method
        $newMockClient = new MockHttpClient([
            new MockResponse('new response', ['http_code' => 500]),
        ]);
        $newCollection = new HttpRecordCollection('test_matcher', $storage);
        $playbackClient = new RecordingHttpClient($newMockClient, $newCollection, RecordingMode::Playback, $matcher);

        // Should match because we only compare method
        $replayResponse = $playbackClient->request('GET', 'https://api.example.com/different-url');
        $this->assertSame('response', $replayResponse->getContent());
    }

    public function testStreamingResponse()
    {
        $chunks = ['chunk1', 'chunk2', 'chunk3'];
        $mockClient = new MockHttpClient([
            new MockResponse($chunks, ['http_code' => 200]),
        ]);

        $storage = new HarStorage($this->tempDir);
        $collection = new HttpRecordCollection('test_streaming', $storage);
        $client = new RecordingHttpClient($mockClient, $collection, RecordingMode::Record);

        $response = $client->request('GET', 'https://api.example.com/stream');
        $content = $response->getContent();

        $this->assertSame('chunk1chunk2chunk3', $content);

        // Verify it can be replayed
        $newCollection = new HttpRecordCollection('test_streaming', $storage);
        $playbackClient = new RecordingHttpClient(new MockHttpClient(), $newCollection, RecordingMode::Playback);

        $replayResponse = $playbackClient->request('GET', 'https://api.example.com/stream');
        $this->assertSame('chunk1chunk2chunk3', $replayResponse->getContent());
    }

    public function testPostRequestWithBody()
    {
        $mockClient = new MockHttpClient([
            new MockResponse('{"id":123}', [
                'http_code' => 201,
                'response_headers' => ['Content-Type' => 'application/json'],
            ]),
        ]);

        $storage = new HarStorage($this->tempDir);
        $collection = new HttpRecordCollection('test_post', $storage);
        $client = new RecordingHttpClient($mockClient, $collection, RecordingMode::Record);

        $response = $client->request('POST', 'https://api.example.com/users', [
            'json' => ['name' => 'John Doe'],
        ]);

        $this->assertSame('{"id":123}', $response->getContent());
        $this->assertSame(201, $response->getStatusCode());
    }

    public function testReset()
    {
        $mockClient = new MockHttpClient([
            new MockResponse('response', ['http_code' => 200]),
        ]);

        $storage = new HarStorage($this->tempDir);
        $collection = new HttpRecordCollection('test_reset', $storage);
        $client = new RecordingHttpClient($mockClient, $collection, RecordingMode::Record);

        $response = $client->request('GET', 'https://api.example.com/data');
        $response->getContent();

        // Reset should save the cassette
        $client->reset();

        $this->assertFileExists($this->tempDir . '/test_reset.har');
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
