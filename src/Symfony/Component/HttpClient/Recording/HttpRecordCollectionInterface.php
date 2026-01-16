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

/**
 * Interface for http record collection storage containers.
 *
 * @author Mathieu Santostefano <msantostefano@protonmail.com>
 */
interface HttpRecordCollectionInterface
{
    public function getName(): string;

    /**
     * @param array<string, mixed> $options
     */
    public function find(string $method, string $url, array $options, RequestMatcherInterface $matcher): ?HttpRecord;

    public function record(HttpRecord $entry): void;

    public function save(): void;

    public function load(): void;

    public function clear(): void;

    public function isEmpty(): bool;

    /**
     * Replaces a record if an equivalent one exists, or adds a new one.
     */
    public function replaceOrAdd(HttpRecord $entry, RequestMatcherInterface $matcher): void;

    /**
     * @return list<HttpRecord>
     */
    public function getEntries(): array;
}
