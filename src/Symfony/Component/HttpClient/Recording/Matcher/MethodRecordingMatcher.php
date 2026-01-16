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
 * Matches a request by its method.
 *
 * @author Mathieu Santostefano <msantostefano@protonmail.com>
 */
final class MethodRecordingMatcher implements RecordingMatcherInterface
{
    public function matches(HttpRecord $entry, string $method, string $url, array $options): bool
    {
        return $entry->method === $method;
    }
}
