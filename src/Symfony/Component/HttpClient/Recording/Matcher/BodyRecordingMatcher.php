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
 * Matches a request by its body.
 *
 * @author Mathieu Santostefano <msantostefano@protonmail.com>
 */
final class BodyRecordingMatcher implements RecordingMatcherInterface
{
    public function matches(HttpRecord $entry, string $method, string $url, array $options): bool
    {
        return hash('xxh128', $entry->requestBody) === hash('xxh128', $this->extractBody($options));
    }

    private function extractBody(array $options): string
    {
        if (!isset($options['body'])) {
            return '';
        }

        $body = $options['body'];

        if (\is_string($body)) {
            return $body;
        }

        if (\is_resource($body)) {
            $content = stream_get_contents($body);
            rewind($body);

            return $content;
        }

        if (\is_callable($body)) {
            $content = '';
            while ('' !== $chunk = $body(65536)) {
                $content .= $chunk;
            }

            return $content;
        }

        return '';
    }
}
