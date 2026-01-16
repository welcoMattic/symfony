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
 * Interface for request matching strategies.
 *
 * This follows the composable pattern from HttpFoundation's RequestMatcherInterface,
 * allowing multiple matchers to be combined for flexible request matching.
 *
 * @author Mathieu Santostefano <msantostefano@protonmail.com>
 */
interface RecordingMatcherInterface
{
    /**
     * @param array<string, mixed> $options
     */
    public function matches(HttpRecord $entry, string $method, string $url, array $options): bool;
}
