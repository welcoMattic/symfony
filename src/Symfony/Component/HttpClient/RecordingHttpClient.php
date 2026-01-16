<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HttpClient;

use Symfony\Component\HttpClient\Exception\NoMatchingRecordingException;
use Symfony\Component\HttpClient\Recording\HttpRecord;
use Symfony\Component\HttpClient\Recording\HttpRecordCollection;
use Symfony\Component\HttpClient\Recording\HttpRecordCollectionInterface;
use Symfony\Component\HttpClient\Recording\RecordingMode;
use Symfony\Component\HttpClient\Recording\RequestMatcher;
use Symfony\Component\HttpClient\Recording\RequestMatcherInterface;
use Symfony\Component\HttpClient\Response\AsyncContext;
use Symfony\Component\HttpClient\Response\AsyncResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\Response\ResponseStream;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Records and replays HTTP responses like PHP-VCR.
 *
 * This decorator can operate in different modes:
 * - Record: Always record new responses
 * - Playback: Only replay recorded responses
 * - Once: Record first time, then replay
 * - NewEpisodes: Replay known requests, record unknown
 * - Disabled: Bypass recording entirely
 *
 * @author Mathieu Santostefano <msantostefano@protonmail.com>
 */
class RecordingHttpClient implements HttpClientInterface, ResetInterface
{
    use AsyncDecoratorTrait {
        stream as asyncStream;
        AsyncDecoratorTrait::withOptions insteadof HttpClientTrait;
    }
    use HttpClientTrait;

    private array $defaultOptions = self::OPTIONS_DEFAULTS;

    public function __construct(
        private HttpClientInterface $client,
        private HttpRecordCollectionInterface $collection,
        private RecordingMode $mode = RecordingMode::NewEpisodes,
        private ?RequestMatcherInterface $matcher = null,
    ) {
        $this->matcher ??= new RequestMatcher();
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        if (RecordingMode::Disabled === $this->mode) {
            return $this->client->request($method, $url, $options);
        }

        [$fullUrl, $options] = self::prepareRequest($method, $url, $options, $this->defaultOptions);
        $fullUrl = implode('', $fullUrl);

        if ($this->shouldPlayback()) {
            $entry = $this->collection->find($method, $fullUrl, $options, $this->matcher);

            if (null !== $entry) {
                return $this->createPlaybackResponse($method, $fullUrl, $options, $entry);
            }

            if (RecordingMode::Playback === $this->mode) {
                throw new NoMatchingRecordingException($method, $fullUrl, $this->collection->getName());
            }
        }

        return $this->createRecordingResponse($method, $fullUrl, $options);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        if ($responses instanceof ResponseInterface) {
            $responses = [$responses];
        }

        $mockResponses = [];
        $asyncResponses = [];

        foreach ($responses as $response) {
            if ($response instanceof MockResponse) {
                $mockResponses[] = $response;
            } else {
                $asyncResponses[] = $response;
            }
        }

        if (!$mockResponses) {
            return $this->asyncStream($asyncResponses, $timeout);
        }

        if (!$asyncResponses) {
            return new ResponseStream(MockResponse::stream($mockResponses, $timeout));
        }

        return new ResponseStream((function () use ($mockResponses, $asyncResponses, $timeout) {
            yield from MockResponse::stream($mockResponses, $timeout);
            yield from $this->asyncStream($asyncResponses, $timeout);
        })());
    }

    public function withMode(RecordingMode $mode): static
    {
        $clone = clone $this;
        $clone->mode = $mode;

        return $clone;
    }

    public function withRecordCollection(HttpRecordCollectionInterface $collection): static
    {
        $this->collection->save();

        $clone = clone $this;
        $clone->collection = $collection;

        return $clone;
    }

    public function withMatcher(RequestMatcherInterface $matcher): static
    {
        $clone = clone $this;
        $clone->matcher = $matcher;

        return $clone;
    }

    public function getRecordCollection(): HttpRecordCollectionInterface
    {
        return $this->collection;
    }

    public function getMode(): RecordingMode
    {
        return $this->mode;
    }

    public function reset(): void
    {
        $this->collection->save();

        if ($this->client instanceof ResetInterface) {
            $this->client->reset();
        }
    }

    public function save(): void
    {
        $this->collection->save();
    }

    private function shouldPlayback(): bool
    {
        return \in_array($this->mode, [
            RecordingMode::Playback,
            RecordingMode::Once,
            RecordingMode::NewEpisodes,
        ], true);
    }

    private function createPlaybackResponse(string $method, string $url, array $options, HttpRecord $entry): MockResponse
    {
        return MockResponse::fromRequest($method, $url, $options, $entry->toMockResponse());
    }

    private function createRecordingResponse(string $method, string $url, array $options): AsyncResponse
    {
        $collection = $this->collection;
        $matcher = $this->matcher;
        $mode = $this->mode;

        return new AsyncResponse(
            $this->client,
            $method,
            $url,
            $options,
            function (ChunkInterface $chunk, AsyncContext $context) use ($method, $url, $options, $collection, $matcher, $mode): \Generator {
                static $chunks = [];
                static $statusCode = null;
                static $headers = [];

                if (null !== $chunk->getError() || $chunk->isTimeout()) {
                    yield $chunk;

                    return;
                }

                if ($chunk->isFirst()) {
                    $statusCode = $context->getStatusCode();
                    $headers = $context->getHeaders();
                    yield $chunk;

                    return;
                }

                if (!$chunk->isLast()) {
                    $chunks[] = $chunk->getContent();
                    yield $chunk;

                    return;
                }

                // Last chunk - combine body and save recording
                $chunks[] = $chunk->getContent();
                $fullBody = implode('', $chunks);

                $entry = HttpRecord::fromRequestResponse(
                    method: $method,
                    url: $url,
                    options: $options,
                    statusCode: $statusCode,
                    responseHeaders: $headers,
                    responseBody: $fullBody,
                    responseInfo: $context->getInfo() ?? [],
                );

                if (RecordingMode::Record === $mode) {
                    $collection->replaceOrAdd($entry, $matcher);
                } else {
                    $collection->record($entry);
                }

                $collection->save();

                yield $chunk;
            }
        );
    }
}
