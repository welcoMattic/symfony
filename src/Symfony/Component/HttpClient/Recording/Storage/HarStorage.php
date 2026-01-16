<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HttpClient\Recording\Storage;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\Internal\HarParser;
use Symfony\Component\HttpClient\Recording\HttpRecord;

/**
 * Stores cassettes in HAR (HTTP Archive) format.
 *
 * HAR is a W3C standard format that is compatible with browser dev tools.
 *
 * @see https://w3c.github.io/web-performance/specs/HAR/Overview.html
 *
 * @author Mathieu Santostefano <msantostefano@protonmail.com>
 */
final class HarStorage implements StorageInterface
{
    private readonly Filesystem $filesystem;

    public function __construct(
        private readonly string $directory,
        ?Filesystem $filesystem = null,
    ) {
        $this->filesystem = $filesystem ?? new Filesystem();
    }

    public function load(string $cassetteName): array
    {
        $path = $this->getPath($cassetteName);

        if (!$this->filesystem->exists($path)) {
            return [];
        }

        $json = json_decode(file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
        $entries = [];

        foreach ($json['log']['entries'] ?? [] as $entry) {
            $entries[] = $this->harEntryToRecordingEntry($entry);
        }

        return $entries;
    }

    public function save(string $cassetteName, array $entries): void
    {
        $harEntries = [];
        foreach ($entries as $entry) {
            $harEntries[] = $this->recordingEntryToHarEntry($entry);
        }

        $har = [
            'log' => [
                'version' => '1.2',
                'creator' => [
                    'name' => 'Symfony HttpClient Recording',
                    'version' => '1.0',
                ],
                'entries' => $harEntries,
            ],
        ];

        $this->filesystem->dumpFile(
            $this->getPath($cassetteName),
            json_encode($har, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)
        );
    }

    public function exists(string $cassetteName): bool
    {
        return $this->filesystem->exists($this->getPath($cassetteName));
    }

    public function delete(string $cassetteName): void
    {
        $path = $this->getPath($cassetteName);

        if ($this->filesystem->exists($path)) {
            $this->filesystem->remove($path);
        }
    }

    public function list(): array
    {
        if (!is_dir($this->directory)) {
            return [];
        }

        $cassettes = [];
        foreach (glob($this->directory . \DIRECTORY_SEPARATOR . '*.har') as $file) {
            $cassettes[] = pathinfo($file, \PATHINFO_FILENAME);
        }

        return $cassettes;
    }

    public function purge(): void
    {
        foreach ($this->list() as $cassetteName) {
            $this->delete($cassetteName);
        }
    }

    private function getPath(string $cassetteName): string
    {
        return $this->directory . \DIRECTORY_SEPARATOR . $cassetteName . '.har';
    }

    private function harEntryToRecordingEntry(array $harEntry): HttpRecord
    {
        $request = $harEntry['request'];
        $response = $harEntry['response'];

        $requestHeaders = HarParser::parseHeaders($request['headers'] ?? []);
        $responseHeaders = HarParser::parseHeaders($response['headers'] ?? []);

        $requestBody = '';
        if (isset($request['postData'])) {
            $requestBody = HarParser::decodeContent($request['postData']);
        }

        $responseBody = '';
        if (isset($response['content'])) {
            $responseBody = HarParser::decodeContent($response['content']);
        }

        $timestamp = isset($harEntry['startedDateTime'])
            ? (int) strtotime($harEntry['startedDateTime'])
            : time();

        return new HttpRecord(
            method: $request['method'],
            url: $request['url'],
            requestHeaders: $requestHeaders,
            requestBody: $requestBody,
            statusCode: $response['status'],
            responseHeaders: $responseHeaders,
            responseBody: $responseBody,
            totalTime: ($harEntry['time'] ?? 0) / 1000,
            timestamp: $timestamp,
            info: $harEntry['_symfony'] ?? [],
        );
    }

    private function recordingEntryToHarEntry(HttpRecord $entry): array
    {
        $requestHeaders = HarParser::formatHeaders($entry->requestHeaders);
        $responseHeaders = HarParser::formatHeaders($entry->responseHeaders);

        $requestBodyEncoded = HttpRecord::encodeBody($entry->requestBody);
        $responseBodyEncoded = HttpRecord::encodeBody($entry->responseBody);

        $harEntry = [
            'startedDateTime' => date('c', $entry->timestamp),
            'time' => $entry->totalTime * 1000,
            'request' => [
                'method' => $entry->method,
                'url' => $entry->url,
                'httpVersion' => 'HTTP/1.1',
                'cookies' => [],
                'headers' => $requestHeaders,
                'queryString' => HarParser::parseQueryString($entry->url),
                'headersSize' => -1,
                'bodySize' => \strlen($entry->requestBody),
            ],
            'response' => [
                'status' => $entry->statusCode,
                'statusText' => HarParser::getStatusText($entry->statusCode),
                'httpVersion' => 'HTTP/1.1',
                'cookies' => [],
                'headers' => $responseHeaders,
                'content' => [
                    'size' => \strlen($entry->responseBody),
                    'mimeType' => HarParser::getMimeType($entry->responseHeaders),
                    'text' => $responseBodyEncoded['text'],
                ] + (null !== $responseBodyEncoded['encoding'] ? ['encoding' => $responseBodyEncoded['encoding']] : []),
                'redirectURL' => '',
                'headersSize' => -1,
                'bodySize' => \strlen($entry->responseBody),
            ],
            'cache' => [],
            'timings' => [
                'blocked' => -1,
                'dns' => -1,
                'connect' => -1,
                'send' => 0,
                'wait' => $entry->totalTime * 1000,
                'receive' => 0,
                'ssl' => -1,
            ],
        ];

        if ('' !== $entry->requestBody) {
            $harEntry['request']['postData'] = [
                'mimeType' => HarParser::getMimeType($entry->requestHeaders),
                'text' => $requestBodyEncoded['text'],
            ] + (null !== $requestBodyEncoded['encoding'] ? ['encoding' => $requestBodyEncoded['encoding']] : []);
        }

        if ($entry->info) {
            $harEntry['_symfony'] = $entry->info;
        }

        return $harEntry;
    }
}
