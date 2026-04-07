<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Notifier\Bridge\Apns;

use Symfony\Component\Notifier\Message\MessageOptionsInterface;

/**
 * @author Mathieu Santostefano <msantostefano@protonmail.com>
 *
 * @see https://developer.apple.com/documentation/usernotifications/sending-notification-requests-to-apns
 */
final class ApnsOptions implements MessageOptionsInterface
{
    public function __construct(
        private string $deviceToken,
        private array $aps = [],
        private array $customData = [],
        private string $pushType = 'alert',
        private int $priority = 10,
        private ?int $expiration = null,
        private ?string $collapseId = null,
    ) {
    }

    public function toArray(): array
    {
        return array_merge(['aps' => $this->aps], $this->customData);
    }

    public function getRecipientId(): ?string
    {
        return $this->deviceToken;
    }

    public function getPushType(): string
    {
        return $this->pushType;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function getExpiration(): ?int
    {
        return $this->expiration;
    }

    public function getCollapseId(): ?string
    {
        return $this->collapseId;
    }

    /**
     * @return $this
     */
    public function deviceToken(string $deviceToken): static
    {
        $this->deviceToken = $deviceToken;

        return $this;
    }

    /**
     * @return $this
     */
    public function pushType(string $pushType): static
    {
        $this->pushType = $pushType;

        return $this;
    }

    /**
     * @return $this
     */
    public function priority(int $priority): static
    {
        $this->priority = $priority;

        return $this;
    }

    /**
     * @return $this
     */
    public function expiration(int $expiration): static
    {
        $this->expiration = $expiration;

        return $this;
    }

    /**
     * @return $this
     */
    public function collapseId(string $collapseId): static
    {
        $this->collapseId = $collapseId;

        return $this;
    }

    /**
     * @return $this
     */
    public function sound(string $sound): static
    {
        $this->aps['sound'] = $sound;

        return $this;
    }

    /**
     * @return $this
     */
    public function badge(int $badge): static
    {
        $this->aps['badge'] = $badge;

        return $this;
    }

    /**
     * @return $this
     */
    public function category(string $category): static
    {
        $this->aps['category'] = $category;

        return $this;
    }

    /**
     * @return $this
     */
    public function threadId(string $threadId): static
    {
        $this->aps['thread-id'] = $threadId;

        return $this;
    }

    /**
     * @return $this
     */
    public function mutableContent(bool $mutableContent): static
    {
        $this->aps['mutable-content'] = (int) $mutableContent;

        return $this;
    }

    /**
     * @return $this
     */
    public function contentAvailable(bool $contentAvailable): static
    {
        $this->aps['content-available'] = (int) $contentAvailable;

        return $this;
    }

    /**
     * @return $this
     */
    public function interruptionLevel(string $interruptionLevel): static
    {
        $this->aps['interruption-level'] = $interruptionLevel;

        return $this;
    }

    /**
     * @return $this
     */
    public function relevanceScore(float $relevanceScore): static
    {
        $this->aps['relevance-score'] = $relevanceScore;

        return $this;
    }

    /**
     * @return $this
     */
    public function data(array $data): static
    {
        $this->customData = $data;

        return $this;
    }
}
