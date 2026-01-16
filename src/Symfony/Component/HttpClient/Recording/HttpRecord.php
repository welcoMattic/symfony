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

use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Represents a single recorded HTTP request/response pair.
 *
 * @author Mathieu Santostefano <msantostefano@protonmail.com>
 */
final readonly class HttpRecord
{
    /**
     * @param array<string, list<string>> $requestHeaders
     * @param array<string, list<string>> $responseHeaders
     * @param array<string, mixed>        $info
     */
    public function __construct(
        public string $method,
        public string $url,
        public array $requestHeaders,
        public string $requestBody,
        public int $statusCode,
        public array $responseHeaders,
        public string $responseBody,
        public float $totalTime,
        public int $timestamp,
        public array $info = [],
    ) {
    }

    public static function fromRequestResponse(
        string $method,
        string $url,
        array $options,
        int $statusCode,
        array $responseHeaders,
        string $responseBody,
        array $responseInfo = [],
    ): self {
        $requestHeaders = [];
        foreach ($options['normalized_headers'] ?? [] as $name => $values) {
            foreach ($values as $value) {
                if (str_contains($value, ': ')) {
                    [, $headerValue] = explode(': ', $value, 2);
                    $requestHeaders[$name][] = $headerValue;
                }
            }
        }

        $requestBody = '';
        if (isset($options['body'])) {
            if (\is_string($options['body'])) {
                $requestBody = $options['body'];
            } elseif (\is_resource($options['body'])) {
                $requestBody = stream_get_contents($options['body']);
                rewind($options['body']);
            }
        }

        return new self(
            method: $method,
            url: $url,
            requestHeaders: $requestHeaders,
            requestBody: $requestBody,
            statusCode: $statusCode,
            responseHeaders: $responseHeaders,
            responseBody: $responseBody,
            totalTime: $responseInfo['total_time'] ?? 0.0,
            timestamp: (int) ($responseInfo['start_time'] ?? time()),
            info: array_filter($responseInfo, static fn($key) => !\in_array($key, ['response_headers', 'http_code', 'url', 'http_method'], true), \ARRAY_FILTER_USE_KEY),
        );
    }

    public function toMockResponse(): MockResponse
    {
        return MockResponse::fromRequest($this->method, $this->url, [], new MockResponse($this->responseBody, [
            'http_code' => $this->statusCode,
            'response_headers' => $this->responseHeaders,
            'total_time' => $this->totalTime,
            'start_time' => $this->timestamp,
            'url' => $this->url,
            'http_method' => $this->method,
        ]));
    }

    public static function decodeBody(string $body, bool $isBase64 = false): string
    {
        return $isBase64 ? base64_decode($body, true) : $body;
    }

    /**
     * @return array{text: string, encoding: string|null}
     */
    public static function encodeBody(string $body): array
    {
        if (!self::isBinary($body)) {
            return ['text' => $body, 'encoding' => null];
        }

        return ['text' => base64_encode($body), 'encoding' => 'base64'];
    }

    private static function isBinary(string $data): bool
    {
        if ('' === $data) {
            return false;
        }

        return !mb_check_encoding($data, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $data);
    }
}
