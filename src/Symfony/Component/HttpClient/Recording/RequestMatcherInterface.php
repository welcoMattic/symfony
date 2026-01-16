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

use Symfony\Component\HttpClient\Recording\Matcher\RecordingMatcherInterface as ComposableMatcherInterface;

/**
 * Interface for request matching strategies.
 *
 * This is the legacy interface. For new implementations, consider using
 * the composable Matcher\RecordingMatcherInterface with ChainRecordingMatcher.
 *
 * @author Mathieu Santostefano <msantostefano@protonmail.com>
 */
interface RequestMatcherInterface extends ComposableMatcherInterface
{
    /**
     * Generates a unique key for a request.
     *
     * This key can be used for quick lookup in the cassette.
     *
     * @param array<string, mixed> $options
     */
    public function generateKey(string $method, string $url, array $options): string;
}
