<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Notifier\Bridge\Apns\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Notifier\Bridge\Apns\ApnsOptions;

/**
 * @author Mathieu Santostefano <msantostefano@protonmail.com>
 */
final class ApnsOptionsTest extends TestCase
{
    public function testGetRecipientId(): void
    {
        $options = new ApnsOptions('device-token-123');
        $this->assertSame('device-token-123', $options->getRecipientId());
    }

    public function testToArray(): void
    {
        $options = (new ApnsOptions('device-token-123'))
            ->sound('default')
            ->badge(5)
            ->category('MESSAGE')
            ->threadId('thread-1')
            ->mutableContent(true)
            ->contentAvailable(true)
            ->interruptionLevel('time-sensitive')
            ->relevanceScore(0.75)
            ->data(['custom-key' => 'custom-value']);

        $expected = [
            'aps' => [
                'sound' => 'default',
                'badge' => 5,
                'category' => 'MESSAGE',
                'thread-id' => 'thread-1',
                'mutable-content' => 1,
                'content-available' => 1,
                'interruption-level' => 'time-sensitive',
                'relevance-score' => 0.75,
            ],
            'custom-key' => 'custom-value',
        ];

        $this->assertSame($expected, $options->toArray());
    }

    public function testDefaults(): void
    {
        $options = new ApnsOptions('device-token-123');

        $this->assertSame('alert', $options->getPushType());
        $this->assertSame(10, $options->getPriority());
        $this->assertNull($options->getExpiration());
        $this->assertNull($options->getCollapseId());
        $this->assertSame(['aps' => []], $options->toArray());
    }

    public function testFluentSetters(): void
    {
        $options = (new ApnsOptions('device-token'))
            ->pushType('background')
            ->priority(5)
            ->expiration(1234567890)
            ->collapseId('group-1')
            ->deviceToken('new-token');

        $this->assertSame('background', $options->getPushType());
        $this->assertSame(5, $options->getPriority());
        $this->assertSame(1234567890, $options->getExpiration());
        $this->assertSame('group-1', $options->getCollapseId());
        $this->assertSame('new-token', $options->getRecipientId());
    }
}
