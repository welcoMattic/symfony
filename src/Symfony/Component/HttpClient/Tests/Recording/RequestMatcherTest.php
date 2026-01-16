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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Recording\HttpRecord;
use Symfony\Component\HttpClient\Recording\RequestMatcher;

#[CoversClass(RequestMatcher::class)]
class RequestMatcherTest extends TestCase
{
    public function testDefaultStrategiesMatchMethodAndUrl()
    {
        $matcher = new RequestMatcher();

        $entry = $this->createEntry('GET', 'https://example.com/api');

        $this->assertTrue($matcher->matches($entry, 'GET', 'https://example.com/api', []));
        $this->assertFalse($matcher->matches($entry, 'POST', 'https://example.com/api', []));
        $this->assertFalse($matcher->matches($entry, 'GET', 'https://example.com/other', []));
    }

    public function testMethodOnlyStrategy()
    {
        $matcher = new RequestMatcher([RequestMatcher::STRATEGY_METHOD]);

        $entry = $this->createEntry('GET', 'https://example.com/api');

        $this->assertTrue($matcher->matches($entry, 'GET', 'https://different.com/path', []));
        $this->assertFalse($matcher->matches($entry, 'POST', 'https://example.com/api', []));
    }

    public function testHostStrategy()
    {
        $matcher = new RequestMatcher([RequestMatcher::STRATEGY_HOST]);

        $entry = $this->createEntry('GET', 'https://example.com/api/v1');

        $this->assertTrue($matcher->matches($entry, 'POST', 'https://example.com/different/path', []));
        $this->assertFalse($matcher->matches($entry, 'GET', 'https://other.com/api/v1', []));
    }

    public function testPathStrategy()
    {
        $matcher = new RequestMatcher([RequestMatcher::STRATEGY_PATH]);

        $entry = $this->createEntry('GET', 'https://example.com/api/users');

        $this->assertTrue($matcher->matches($entry, 'POST', 'https://other.com/api/users', []));
        $this->assertFalse($matcher->matches($entry, 'GET', 'https://example.com/api/posts', []));
    }

    public function testQueryStrategy()
    {
        $matcher = new RequestMatcher([RequestMatcher::STRATEGY_QUERY]);

        $entry = $this->createEntry('GET', 'https://example.com/api?foo=bar&baz=qux');

        // Query order should not matter
        $this->assertTrue($matcher->matches($entry, 'POST', 'https://other.com/different?baz=qux&foo=bar', []));
        $this->assertFalse($matcher->matches($entry, 'GET', 'https://example.com/api?foo=bar', []));
    }

    public function testBodyStrategy()
    {
        $matcher = new RequestMatcher([RequestMatcher::STRATEGY_BODY]);

        $entry = new HttpRecord(
            method: 'POST',
            url: 'https://example.com/api',
            requestHeaders: [],
            requestBody: '{"name":"test"}',
            statusCode: 200,
            responseHeaders: [],
            responseBody: '',
            totalTime: 0.1,
            timestamp: time(),
        );

        $this->assertTrue($matcher->matches($entry, 'GET', 'https://other.com', ['body' => '{"name":"test"}']));
    }

    public function testHeadersStrategy()
    {
        $matcher = new RequestMatcher(
            [RequestMatcher::STRATEGY_HEADERS],
            ['authorization']
        );

        $entry = new HttpRecord(
            method: 'GET',
            url: 'https://example.com/api',
            requestHeaders: ['authorization' => ['Bearer token123']],
            requestBody: '',
            statusCode: 200,
            responseHeaders: [],
            responseBody: '',
            totalTime: 0.1,
            timestamp: time(),
        );

        $options = [
            'normalized_headers' => [
                'authorization' => ['Authorization: Bearer token123'],
            ],
        ];

        $this->assertTrue($matcher->matches($entry, 'POST', 'https://other.com', $options));

        $options['normalized_headers']['authorization'] = ['Authorization: Bearer different'];
        $this->assertFalse($matcher->matches($entry, 'GET', 'https://example.com/api', $options));
    }

    public function testCombinedStrategies()
    {
        $matcher = new RequestMatcher([
            RequestMatcher::STRATEGY_METHOD,
            RequestMatcher::STRATEGY_HOST,
            RequestMatcher::STRATEGY_PATH,
        ]);

        $entry = $this->createEntry('GET', 'https://api.example.com/v1/users?page=1');

        // Method, host, and path match (query ignored)
        $this->assertTrue($matcher->matches($entry, 'GET', 'https://api.example.com/v1/users?page=2', []));

        // Method differs
        $this->assertFalse($matcher->matches($entry, 'POST', 'https://api.example.com/v1/users', []));

        // Host differs
        $this->assertFalse($matcher->matches($entry, 'GET', 'https://other.example.com/v1/users', []));

        // Path differs
        $this->assertFalse($matcher->matches($entry, 'GET', 'https://api.example.com/v2/users', []));
    }

    public function testGenerateKey()
    {
        $matcher = new RequestMatcher([RequestMatcher::STRATEGY_METHOD, RequestMatcher::STRATEGY_URL]);

        $key1 = $matcher->generateKey('GET', 'https://example.com/api', []);
        $key2 = $matcher->generateKey('GET', 'https://example.com/api', []);
        $key3 = $matcher->generateKey('POST', 'https://example.com/api', []);

        $this->assertSame($key1, $key2);
        $this->assertNotSame($key1, $key3);
    }

    public function testWithStrategies()
    {
        $matcher = new RequestMatcher([RequestMatcher::STRATEGY_METHOD]);
        $newMatcher = $matcher->withStrategies([RequestMatcher::STRATEGY_URL]);

        $entry = $this->createEntry('GET', 'https://example.com/api');

        // Original matcher: method only
        $this->assertTrue($matcher->matches($entry, 'GET', 'https://other.com', []));

        // New matcher: URL only
        $this->assertTrue($newMatcher->matches($entry, 'POST', 'https://example.com/api', []));
    }

    public function testWithHeaderWhitelist()
    {
        $matcher = new RequestMatcher([RequestMatcher::STRATEGY_HEADERS], ['content-type']);
        $newMatcher = $matcher->withHeaderWhitelist(['authorization']);

        $entry = new HttpRecord(
            method: 'GET',
            url: 'https://example.com/api',
            requestHeaders: [
                'content-type' => ['application/json'],
                'authorization' => ['Bearer token'],
            ],
            requestBody: '',
            statusCode: 200,
            responseHeaders: [],
            responseBody: '',
            totalTime: 0.1,
            timestamp: time(),
        );

        $options = [
            'normalized_headers' => [
                'content-type' => ['Content-Type: application/json'],
                'authorization' => ['Authorization: Bearer different'],
            ],
        ];

        // Original matcher only checks content-type
        $this->assertTrue($matcher->matches($entry, 'GET', 'https://example.com/api', $options));

        // New matcher only checks authorization
        $this->assertFalse($newMatcher->matches($entry, 'GET', 'https://example.com/api', $options));
    }

    #[DataProvider('urlParsingProvider')]
    public function testUrlParsing(string $strategy, string $entryUrl, string $requestUrl, bool $expected)
    {
        $matcher = new RequestMatcher([$strategy]);
        $entry = $this->createEntry('GET', $entryUrl);

        $this->assertSame($expected, $matcher->matches($entry, 'GET', $requestUrl, []));
    }

    public static function urlParsingProvider(): iterable
    {
        yield 'host match' => [RequestMatcher::STRATEGY_HOST, 'https://example.com/path', 'https://example.com/other', true];
        yield 'host mismatch' => [RequestMatcher::STRATEGY_HOST, 'https://example.com', 'https://other.com', false];
        yield 'path match' => [RequestMatcher::STRATEGY_PATH, 'https://example.com/api/v1', 'https://other.com/api/v1', true];
        yield 'path mismatch' => [RequestMatcher::STRATEGY_PATH, 'https://example.com/api/v1', 'https://example.com/api/v2', false];
        yield 'query match sorted' => [RequestMatcher::STRATEGY_QUERY, 'https://example.com?a=1&b=2', 'https://example.com?b=2&a=1', true];
        yield 'query mismatch' => [RequestMatcher::STRATEGY_QUERY, 'https://example.com?a=1', 'https://example.com?a=2', false];
    }

    private function createEntry(string $method, string $url): HttpRecord
    {
        return new HttpRecord(
            method: $method,
            url: $url,
            requestHeaders: [],
            requestBody: '',
            statusCode: 200,
            responseHeaders: [],
            responseBody: '',
            totalTime: 0.1,
            timestamp: time(),
        );
    }
}
