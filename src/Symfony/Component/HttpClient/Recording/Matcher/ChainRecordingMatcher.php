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
 * A recorder matcher that delegates to a list of other matchers.
 *
 * @author Mathieu Santostefano <msantostefano@protonmail.com>
 */
final class ChainRecordingMatcher implements RecordingMatcherInterface
{
    /**
     * @param iterable<RecordingMatcherInterface> $matchers
     */
    public function __construct(
        private readonly iterable $matchers,
    ) {
    }

    public function matches(HttpRecord $entry, string $method, string $url, array $options): bool
    {
        foreach ($this->matchers as $matcher) {
            if (!$matcher->matches($entry, $method, $url, $options)) {
                return false;
            }
        }

        return true;
    }
}
