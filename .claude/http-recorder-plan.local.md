Symfony HTTP Recorder - Implementation Plan

Overview

Implement a PHP-VCR-like HTTP recording/replay system for Symfony's HttpClient component. This provides a
modern, Symfony-native way to record HTTP interactions and replay them for testing, development mocking, and
request auditing.

Architecture Decision

Approach: Create a new RecordingHttpClient decorator following the established patterns from
CachingHttpClient and TraceableHttpClient.

Key insight: Symfony already has HarFileResponseFactory for HAR playback. We'll extend this into a complete
recording system with:
- RecordingHttpClient - Main decorator (pattern from CachingHttpClient)
- Cassette - Storage container for recordings
- RequestMatcher - Configurable request matching
- HAR format as primary storage (W3C standard, browser dev tools compatible)

Decisions

- Scope: Component only (no FrameworkBundle integration in this PR)
- Storage formats: HAR (W3C standard) + JSON (simpler debugging)
- Naming: Recording* prefix (RecordingHttpClient, RecordingEntry, etc.)

Files to Create

Core Component (src/Symfony/Component/HttpClient/)

Recording/
RecordingHttpClient.php      # Main decorator
Cassette.php                 # Storage container
CassetteInterface.php        # Contract
RecordingEntry.php           # Single request/response pair (value object)
RecordingMode.php            # Enum: Record, Playback, Once, NewEpisodes, Disabled
RequestMatcher.php           # Configurable matching logic
RequestMatcherInterface.php  # Contract
Storage/
StorageInterface.php       # Storage backend contract
HarStorage.php             # HAR format (extend existing HarFileResponseFactory logic)
JsonStorage.php            # Simple JSON format for easier debugging
Exception/
NoMatchingRecordingException.php
RecordingDisabledException.php

Tests (src/Symfony/Component/HttpClient/Tests/)

RecordingHttpClientTest.php
Recording/
CassetteTest.php
RequestMatcherTest.php
Storage/HarStorageTest.php

Implementation Details

1. RecordingMode Enum

enum RecordingMode: string
{
case Record = 'record';           // Always record, replace existing
case Playback = 'playback';       // Only replay, error on unknown
case Once = 'once';               // Record first time, then replay
case NewEpisodes = 'new_episodes'; // Replay known, record unknown
case Disabled = 'disabled';        // Bypass entirely
}

2. RecordingHttpClient (Main Decorator)

Follow CachingHttpClient pattern:
- Use AsyncDecoratorTrait for streaming support
- Use HttpClientTrait for URL/option normalization
- Intercept chunks via AsyncResponse callback
- Create MockResponse for playback

class RecordingHttpClient implements HttpClientInterface, ResetInterface
{
use AsyncDecoratorTrait;
use HttpClientTrait;

     public function __construct(
         private HttpClientInterface $client,
         private Cassette $cassette,
         private RecordingMode $mode = RecordingMode::NewEpisodes,
         private ?RequestMatcherInterface $matcher = null,
     ) {}

     public function request(string $method, string $url, array $options = []): ResponseInterface
     {
         // 1. Normalize request via prepareRequest()
         // 2. Check cassette for match (if mode allows playback)
         // 3. If match: return MockResponse::fromRequest()
         // 4. If no match + recording allowed: make real request with chunk capture
         // 5. Store recording in cassette
     }
}

3. RecordingEntry (Value Object)

final readonly class RecordingEntry
{
public function __construct(
public string $method,
public string $url,
public array $requestHeaders,
public string $requestBody,
public int $statusCode,
public array $responseHeaders,
public string $responseBody,  // base64 for binary safety
public float $totalTime,
public int $timestamp,
public array $info = [],
) {}

     public static function fromRequestResponse(...): self;
     public function toMockResponse(): MockResponse;
}

4. RequestMatcher

Configurable matching strategies:
- method - HTTP method
- url - Full URL
- host - Host only
- path - Path only
- query - Query parameters
- headers - Selected headers (whitelist)
- body - Request body hash

Default: ['method', 'url'] (like PHP-VCR)

5. HarStorage

Extend logic from existing HarFileResponseFactory:
- Read: Parse HAR JSON, convert to RecordingEntry[]
- Write: Convert RecordingEntry[] to HAR JSON
- Base64 encode binary content
- Store Symfony-specific metadata in _symfony extension field

6. Cassette

final class Cassette implements CassetteInterface
{
private array $entries = [];

     public function find(string $method, string $url, array $options, RequestMatcherInterface $matcher):
?RecordingEntry;
public function record(RecordingEntry $entry): void;
public function save(): void;
public function clear(): void;
}

Streaming Support

Critical: Must handle streaming responses properly.

Pattern from CachingHttpClient:
return new AsyncResponse($this->client, $method, $url, $options,
function (ChunkInterface $chunk, AsyncContext $context) use (...) {
static $chunks = [];

         if ($chunk->isFirst()) {
             // Capture status + headers
         }

         if (!$chunk->isLast()) {
             $chunks[] = $chunk->getContent();
         }

         if ($chunk->isLast()) {
             // Combine chunks, create RecordingEntry, save to cassette
             $fullBody = implode('', $chunks);
             $this->cassette->record(RecordingEntry::fromRequestResponse(...));
         }

         yield $chunk; // Pass through to consumer
     }
);

Usage Examples

Standalone

$storage = new HarStorage('/path/to/recordings');
$cassette = new Cassette('api_tests', $storage);
$client = new RecordingHttpClient(HttpClient::create(), $cassette);

$response = $client->request('GET', 'https://api.example.com/users');

Switching Modes

$client = $client->withMode(RecordingMode::Playback);
$client = $client->withCassette(new Cassette('other', $storage));

Custom Matching

$matcher = new RequestMatcher(
strategies: ['method', 'url', 'headers'],
headerWhitelist: ['authorization', 'content-type'],
);
$client = new RecordingHttpClient($httpClient, $cassette, matcher: $matcher);

Critical Files to Reference
┌─────────────────────────────────┬─────────────────────────────────────────────────────────┐
│              File               │                 Pattern/Logic to Follow                 │
├─────────────────────────────────┼─────────────────────────────────────────────────────────┤
│ CachingHttpClient.php           │ AsyncResponse chunk interception, MockResponse creation │
├─────────────────────────────────┼─────────────────────────────────────────────────────────┤
│ TraceableHttpClient.php         │ Request metadata capture, ArrayObject storage           │
├─────────────────────────────────┼─────────────────────────────────────────────────────────┤
│ Test/HarFileResponseFactory.php │ HAR parsing, base64 handling                            │
├─────────────────────────────────┼─────────────────────────────────────────────────────────┤
│ Response/MockResponse.php       │ fromRequest() factory for playback                      │
├─────────────────────────────────┼─────────────────────────────────────────────────────────┤
│ HttpClientTrait.php             │ prepareRequest() for URL/option normalization           │
└─────────────────────────────────┴─────────────────────────────────────────────────────────┘
Implementation Order

1. Phase 1: Core Infrastructure
- RecordingMode enum
- RecordingEntry value object
- StorageInterface + HarStorage + JsonStorage
- Cassette class
2. Phase 2: Matching
- RequestMatcherInterface
- RequestMatcher with configurable strategies
3. Phase 3: Main Decorator
- RecordingHttpClient with full mode support
- Streaming via AsyncResponse pattern
4. Phase 4: Exceptions
- NoMatchingRecordingException
- RecordingDisabledException
5. Phase 5: Tests
- Unit tests for all components
- Integration tests with MockHttpClient

Verification Plan

1. Unit Tests
   ./phpunit src/Symfony/Component/HttpClient/Tests/RecordingHttpClientTest.php
2. Manual Testing
- Create test script that records real HTTP call
- Verify HAR file is created correctly
- Switch to playback mode, verify response matches
- Test all modes (record, playback, once, new_episodes)
3. Streaming Test
- Record a large response
- Verify chunked body is captured completely
- Playback and verify content matches
4. HAR Compatibility
- Import HAR file exported from browser dev tools
- Verify playback works correctly
