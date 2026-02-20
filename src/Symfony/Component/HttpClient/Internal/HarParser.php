<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HttpClient\Internal;

/**
 * Shared utilities for parsing and generating HAR (HTTP Archive) format.
 *
 * @internal
 *
 * @see https://w3c.github.io/web-performance/specs/HAR/Overview.html
 *
 * @author Mathieu Santostefano <msantostefano@protonmail.com>
 */
final class HarParser
{
    /**
     * Decodes content from HAR format (handles base64 encoding).
     *
     * @param array{text?: string, encoding?: string|null} $content
     */
    public static function decodeContent(array $content): string
    {
        $text = $content['text'] ?? '';
        $encoding = $content['encoding'] ?? null;

        return match ($encoding) {
            'base64' => base64_decode($text, true) ?: '',
            default => $text,
        };
    }

    /**
     * Parses HAR headers array into normalized key => values format.
     *
     * @param array<int, array{name: string, value: string}> $harHeaders
     *
     * @return array<string, list<string>>
     */
    public static function parseHeaders(array $harHeaders): array
    {
        $headers = [];
        foreach ($harHeaders as $header) {
            $name = strtolower($header['name']);
            $headers[$name][] = $header['value'];
        }

        return $headers;
    }

    /**
     * Formats headers to HAR format.
     *
     * @param array<string, list<string>> $headers
     *
     * @return array<int, array{name: string, value: string}>
     */
    public static function formatHeaders(array $headers): array
    {
        $harHeaders = [];
        foreach ($headers as $name => $values) {
            foreach ($values as $value) {
                $harHeaders[] = ['name' => $name, 'value' => $value];
            }
        }

        return $harHeaders;
    }

    /**
     * Parses query string from URL into HAR format.
     *
     * @return array<int, array{name: string, value: string}>
     */
    public static function parseQueryString(string $url): array
    {
        $parsed = parse_url($url);
        if (!isset($parsed['query'])) {
            return [];
        }

        $queryString = [];
        parse_str($parsed['query'], $params);

        foreach ($params as $name => $value) {
            if (\is_array($value)) {
                foreach ($value as $v) {
                    $queryString[] = ['name' => $name . '[]', 'value' => (string) $v];
                }
            } else {
                $queryString[] = ['name' => $name, 'value' => (string) $value];
            }
        }

        return $queryString;
    }

    /**
     * Gets MIME type from headers.
     *
     * @param array<string, list<string>> $headers
     */
    public static function getMimeType(array $headers): string
    {
        foreach ($headers as $name => $values) {
            if ('content-type' !== strtolower($name)) {
                continue;
            }

            $contentType = $values[0] ?? '';
            if (!str_contains($contentType, ';')) {
                return $contentType;
            }

            return explode(';', $contentType)[0];

        }

        return 'application/octet-stream';
    }
}
