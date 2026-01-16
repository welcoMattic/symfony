<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HttpClient\Recording\Storage;

use Symfony\Component\HttpClient\Recording\HttpRecord;

/**
 * Interface for cassette storage.
 *
 * @author Mathieu Santostefano <msantostefano@protonmail.com>
 */
interface StorageInterface
{
    /**
     * @return list<HttpRecord>
     */
    public function load(string $cassetteName): array;

    /**
     * @param list<HttpRecord> $entries
     */
    public function save(string $cassetteName, array $entries): void;

    public function exists(string $cassetteName): bool;

    public function delete(string $cassetteName): void;

    /**
     * @return list<string>
     */
    public function list(): array;

    public function purge(): void;
}
