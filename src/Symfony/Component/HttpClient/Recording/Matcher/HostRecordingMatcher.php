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
 * Matches a request by its host.
 *
 * @author Mathieu Santostefano <msantostefano@protonmail.com>
 */
final class HostRecordingMatcher implements RecordingMatcherInterface
{
    public function matches(HttpRecord $entry, string $method, string $url, array $options): bool
    {
        return $this->extractHost($entry->url) === $this->extractHost($url);
    }

    private function extractHost(string $url): string
    {
        return parse_url($url, \PHP_URL_HOST) ?? '';
    }
}
