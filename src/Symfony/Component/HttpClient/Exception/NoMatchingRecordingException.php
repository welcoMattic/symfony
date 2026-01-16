<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HttpClient\Exception;

use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Thrown when no matching recording is found in playback mode.
 *
 * @author Mathieu Santostefano <msantostefano@protonmail.com>
 */
class NoMatchingRecordingException extends \RuntimeException implements TransportExceptionInterface
{
    public function __construct(
        private readonly string $method,
        private readonly string $url,
        private readonly string $collectionName,
    ) {
        parent::__construct(\sprintf(
            'No recording found for "%s %s" in collection "%s". Run in Record or NewEpisodes mode to record this request.',
            $method,
            $url,
            $collectionName
        ));
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getCollectionName(): string
    {
        return $this->collectionName;
    }
}
