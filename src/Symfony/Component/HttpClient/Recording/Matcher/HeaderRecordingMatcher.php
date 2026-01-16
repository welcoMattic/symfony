<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HttpClient\Recording\Matcher;

use Symfony\Component\HttpClient\Recording\HttpRecord;

/**
 * Matches a request by its headers.
 *
 * @author Mathieu Santostefano <msantostefano@protonmail.com>
 */
final class HeaderRecordingMatcher implements RecordingMatcherInterface
{
    /**
     * @param list<string>|null $whitelist Headers to include when matching (null = all headers)
     */
    public function __construct(
        private readonly ?array $whitelist = null,
    ) {
    }

    public function matches(HttpRecord $entry, string $method, string $url, array $options): bool
    {
        $requestHeaders = $this->extractHeaders($options);
        $entryHeaders = $entry->requestHeaders;

        if (null !== $this->whitelist) {
            $requestHeaders = $this->filterHeaders($requestHeaders, $this->whitelist);
            $entryHeaders = $this->filterHeaders($entryHeaders, $this->whitelist);
        }

        return $this->normalizeHeaders($requestHeaders) === $this->normalizeHeaders($entryHeaders);
    }

    /**
     * Extracts headers from request options.
     *
     * @return array<string, list<string>>
     */
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

    /**
     * Filters headers to only include those in the whitelist.
     *
     * @param array<string, list<string>> $headers
     * @param list<string>                $whitelist
     *
     * @return array<string, list<string>>
     */
    private function filterHeaders(array $headers, array $whitelist): array
    {
        $whitelist = array_map('strtolower', $whitelist);
        $filtered = [];

        foreach ($headers as $name => $values) {
            if (\in_array(strtolower($name), $whitelist, true)) {
                $filtered[$name] = $values;
            }
        }

        return $filtered;
    }

    /**
     * Normalizes headers for comparison.
     *
     * @param array<string, list<string>> $headers
     *
     * @return array<string, list<string>>
     */
    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $values) {
            $name = strtolower($name);
            sort($values);
            $normalized[$name] = $values;
        }
        ksort($normalized);

        return $normalized;
    }
}
