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
 * Matches a request by its query parameters.
 *
 * Normalizes query parameters by sorting them before comparison.
 *
 * @author Mathieu Santostefano <msantostefano@protonmail.com>
 */
final class QueryRecordingMatcher implements RecordingMatcherInterface
{
    public function matches(HttpRecord $entry, string $method, string $url, array $options): bool
    {
        return $this->normalizeQuery($entry->url) === $this->normalizeQuery($url);
    }

    private function normalizeQuery(string $url): string
    {
        $query = parse_url($url, \PHP_URL_QUERY);

        if (null === $query || '' === $query) {
            return '';
        }

        parse_str($query, $params);
        ksort($params);

        return http_build_query($params);
    }
}
