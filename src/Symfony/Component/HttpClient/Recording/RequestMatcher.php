<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HttpClient\Recording;

use Symfony\Component\HttpClient\Recording\Matcher\BodyRecordingMatcher;
use Symfony\Component\HttpClient\Recording\Matcher\ChainRecordingMatcher;
use Symfony\Component\HttpClient\Recording\Matcher\HeaderRecordingMatcher;
use Symfony\Component\HttpClient\Recording\Matcher\HostRecordingMatcher;
use Symfony\Component\HttpClient\Recording\Matcher\MethodRecordingMatcher;
use Symfony\Component\HttpClient\Recording\Matcher\PathRecordingMatcher;
use Symfony\Component\HttpClient\Recording\Matcher\QueryRecordingMatcher;
use Symfony\Component\HttpClient\Recording\Matcher\RecordingMatcherInterface;
use Symfony\Component\HttpClient\Recording\Matcher\UrlRecordingMatcher;

/**
 * Configurable request matching for recorded HTTP interactions.
 *
 * This class provides a convenient facade over the composable matchers found in
 * the Matcher\ namespace. For maximum flexibility, use the composable matchers
 * directly with ChainRecordingMatcher.
 *
 * Supports multiple matching strategies that can be combined:
 * - method: Match HTTP method (GET, POST, etc.)
 * - url: Match full URL
 * - host: Match only the host part of the URL
 * - path: Match only the path part of the URL
 * - query: Match query parameters
 * - headers: Match selected request headers
 * - body: Match request body (via hash)
 *
 * @author Mathieu Santostefano <msantostefano@protonmail.com>
 */
final class RequestMatcher implements RequestMatcherInterface
{
    public const STRATEGY_METHOD = 'method';
    public const STRATEGY_URL = 'url';
    public const STRATEGY_HOST = 'host';
    public const STRATEGY_PATH = 'path';
    public const STRATEGY_QUERY = 'query';
    public const STRATEGY_HEADERS = 'headers';
    public const STRATEGY_BODY = 'body';

    /**
     * Default strategies matching PHP-VCR behavior.
     */
    public const DEFAULT_STRATEGIES = [self::STRATEGY_METHOD, self::STRATEGY_URL];

    private readonly ChainRecordingMatcher $chainMatcher;

    /** @var list<RecordingMatcherInterface> */
    private readonly array $matchers;

    /**
     * @param list<string>      $strategies      List of matching strategies to use
     * @param list<string>|null $headerWhitelist Headers to include when matching (null = all headers)
     */
    public function __construct(
        private readonly array $strategies = self::DEFAULT_STRATEGIES,
        private readonly ?array $headerWhitelist = null,
    ) {
        $this->matchers = $this->buildMatchers();
        $this->chainMatcher = new ChainRecordingMatcher($this->matchers);
    }

    public function matches(HttpRecord $entry, string $method, string $url, array $options): bool
    {
        return $this->chainMatcher->matches($entry, $method, $url, $options);
    }

    public function generateKey(string $method, string $url, array $options): string
    {
        $parts = [];

        foreach ($this->strategies as $strategy) {
            $part = match ($strategy) {
                self::STRATEGY_METHOD => $method,
                self::STRATEGY_URL => $url,
                self::STRATEGY_HOST => $this->extractHost($url),
                self::STRATEGY_PATH => $this->extractPath($url),
                self::STRATEGY_QUERY => $this->extractQuery($url),
                self::STRATEGY_HEADERS => $this->extractHeadersHash($options),
                self::STRATEGY_BODY => $this->extractBodyHash($options),
                default => '',
            };

            $parts[] = $part;
        }

        return hash('xxh128', implode('|', $parts));
    }

    public function withStrategies(array $strategies): self
    {
        return new self($strategies, $this->headerWhitelist);
    }

    public function withHeaderWhitelist(array $headerWhitelist): self
    {
        return new self($this->strategies, $headerWhitelist);
    }

    public function getMatchers(): array
    {
        return $this->matchers;
    }

    /**
     * @return list<RecordingMatcherInterface>
     */
    private function buildMatchers(): array
    {
        $matchers = [];
        foreach ($this->strategies as $strategy) {
            $matcher = match ($strategy) {
                self::STRATEGY_METHOD => new MethodRecordingMatcher(),
                self::STRATEGY_URL => new UrlRecordingMatcher(),
                self::STRATEGY_HOST => new HostRecordingMatcher(),
                self::STRATEGY_PATH => new PathRecordingMatcher(),
                self::STRATEGY_QUERY => new QueryRecordingMatcher(),
                self::STRATEGY_HEADERS => new HeaderRecordingMatcher($this->headerWhitelist),
                self::STRATEGY_BODY => new BodyRecordingMatcher(),
                default => null,
            };

            if (null !== $matcher) {
                $matchers[] = $matcher;
            }
        }

        return $matchers;
    }

    private function extractHost(string $url): string
    {
        return parse_url($url, \PHP_URL_HOST) ?? '';
    }

    private function extractPath(string $url): string
    {
        return parse_url($url, \PHP_URL_PATH) ?? '/';
    }

    private function extractQuery(string $url): string
    {
        $query = parse_url($url, \PHP_URL_QUERY);

        if (null === $query || '' === $query) {
            return '';
        }

        parse_str($query, $params);
        ksort($params);

        return http_build_query($params);
    }

    private function matchMethod(HttpRecord $entry, string $method): bool
    {
        return $entry->method === $method;
    }

    private function matchUrl(HttpRecord $entry, string $url): bool
    {
        return $entry->url === $url;
    }

    private function matchHost(HttpRecord $entry, string $url): bool
    {
        return $this->extractHost($entry->url) === $this->extractHost($url);
    }

    private function matchPath(HttpRecord $entry, string $url): bool
    {
        return $this->extractPath($entry->url) === $this->extractPath($url);
    }

    private function matchQuery(HttpRecord $entry, string $url): bool
    {
        return $this->extractQuery($entry->url) === $this->extractQuery($url);
    }

    private function matchHeaders(HttpRecord $entry, array $options): bool
    {
        $requestHeaders = $this->extractHeaders($options);
        $entryHeaders = $entry->requestHeaders;

        if (null !== $this->headerWhitelist) {
            $requestHeaders = $this->filterHeaders($requestHeaders, $this->headerWhitelist);
            $entryHeaders = $this->filterHeaders($entryHeaders, $this->headerWhitelist);
        }

        return $this->normalizeHeaders($requestHeaders) === $this->normalizeHeaders($entryHeaders);
    }

    private function matchBody(HttpRecord $entry, array $options): bool
    {
        $requestBody = $this->extractBody($options);

        return $entry->requestBody === $requestBody;
    }

    private function extractBody(array $options): string
    {
        if (!isset($options['body'])) {
            return '';
        }

        if (\is_string($options['body'])) {
            return $options['body'];
        }

        if (\is_resource($options['body'])) {
            $content = stream_get_contents($options['body']);
            rewind($options['body']);

            return $content;
        }

        return '';
    }

    private function extractHeaders(array $options): array
    {
        $headers = [];
        foreach ($options['normalized_headers'] ?? [] as $name => $values) {
            foreach ($values as $value) {
                if (str_contains($value, ': ')) {
                    [, $headerValue] = explode(': ', $value, 2);
                    $headers[$name][] = $headerValue;
                }
            }
        }

        return $headers;
    }

    private function extractHeadersHash(array $options): string
    {
        $headers = $this->extractHeaders($options);

        if (null !== $this->headerWhitelist) {
            $headers = $this->filterHeaders($headers, $this->headerWhitelist);
        }

        return hash('xxh128', serialize($this->normalizeHeaders($headers)));
    }


    private function extractBodyHash(array $options): string
    {
        return hash('xxh128', $this->extractBody($options));
    }

    /**
     * @param array<string, list<string>> $headers
     * @param list<string>                $whitelist
     *
     * @return array<string, list<string>>
     */
    private function filterHeaders(array $headers, array $whitelist): array
    {
        $whitelist = array_map('strtolower', $whitelist);

        return array_filter(
            $headers,
            static fn(string $name) => \in_array(strtolower($name), $whitelist, true),
            \ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * @param array<string, list<string>> $headers
     *
     * @return array<string, list<string>>
     */
    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $values) {
            $normalized[strtolower($name)] = $values;
        }
        ksort($normalized);

        return $normalized;
    }
}
