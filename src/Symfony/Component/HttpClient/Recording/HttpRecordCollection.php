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

use Symfony\Component\HttpClient\Recording\Storage\StorageInterface;

/**
 * A collection holds recorded HTTP interactions.
 *
 * @author Mathieu Santostefano <msantostefano@protonmail.com>
 */
final class HttpRecordCollection implements HttpRecordCollectionInterface
{
    /** @var list<HttpRecord> */
    private array $entries = [];
    private bool $loaded = false;
    private bool $dirty = false;

    public function __construct(
        private readonly string $name,
        private readonly StorageInterface $storage,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function find(string $method, string $url, array $options, RequestMatcherInterface $matcher): ?HttpRecord
    {
        $this->ensureLoaded();

        return array_find($this->entries, fn($entry) => $matcher->matches($entry, $method, $url, $options));

    }

    public function record(HttpRecord $entry): void
    {
        $this->ensureLoaded();
        $this->entries[] = $entry;
        $this->dirty = true;
    }

    public function save(): void
    {
        if (!$this->dirty) {
            return;
        }

        $this->storage->save($this->name, $this->entries);
        $this->dirty = false;
    }

    public function load(): void
    {
        $this->entries = $this->storage->load($this->name);
        $this->loaded = true;
        $this->dirty = false;
    }

    public function clear(): void
    {
        $this->entries = [];
        $this->loaded = true;
        $this->dirty = true;
    }

    public function isEmpty(): bool
    {
        $this->ensureLoaded();

        return [] === $this->entries;
    }

    public function getEntries(): array
    {
        $this->ensureLoaded();

        return $this->entries;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function findAndRemove(string $method, string $url, array $options, RequestMatcherInterface $matcher): ?HttpRecord
    {
        $this->ensureLoaded();

        foreach ($this->entries as $index => $entry) {
            if ($matcher->matches($entry, $method, $url, $options)) {
                unset($this->entries[$index]);
                $this->entries = array_values($this->entries);
                $this->dirty = true;

                return $entry;
            }
        }

        return null;
    }

    public function replaceOrAdd(HttpRecord $entry, RequestMatcherInterface $matcher): void
    {
        $this->ensureLoaded();

        foreach ($this->entries as $index => $existingEntry) {
            if ($matcher->matches($existingEntry, $entry->method, $entry->url, [])) {
                $this->entries[$index] = $entry;
                $this->dirty = true;

                return;
            }
        }

        $this->entries[] = $entry;
        $this->dirty = true;
    }

    private function ensureLoaded(): void
    {
        if (!$this->loaded) {
            $this->load();
        }
    }
}
