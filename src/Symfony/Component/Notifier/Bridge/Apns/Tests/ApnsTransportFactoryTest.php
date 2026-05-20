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

use Symfony\Component\Notifier\Bridge\Apns\ApnsTransportFactory;
use Symfony\Component\Notifier\Test\AbstractTransportFactoryTestCase;
use Symfony\Component\Notifier\Test\IncompleteDsnTestTrait;

/**
 * @author Mathieu Santostefano <msantostefano@protonmail.com>
 */
final class ApnsTransportFactoryTest extends AbstractTransportFactoryTestCase
{
    use IncompleteDsnTestTrait;

    public function createFactory(): ApnsTransportFactory
    {
        return new ApnsTransportFactory();
    }

    public static function createProvider(): iterable
    {
        yield [
            'apns://keyId:teamId@api.push.apple.com?topic=com.example.app',
            'apns://keyId:teamId@default?privateKey=dGVzdA%3D%3D&topic=com.example.app',
        ];

        yield [
            'apns-sandbox://keyId:teamId@api.sandbox.push.apple.com?topic=com.example.app',
            'apns-sandbox://keyId:teamId@default?privateKey=dGVzdA%3D%3D&topic=com.example.app',
        ];
    }

    public static function supportsProvider(): iterable
    {
        yield [true, 'apns://keyId:teamId@default?privateKey=dGVzdA%3D%3D&topic=com.example.app'];
        yield [true, 'apns-sandbox://keyId:teamId@default?privateKey=dGVzdA%3D%3D&topic=com.example.app'];
        yield [false, 'somethingElse://keyId:teamId@default'];
    }

    public static function incompleteDsnProvider(): iterable
    {
        yield 'missing key ID' => ['apns://:teamId@default?privateKey=dGVzdA%3D%3D&topic=com.example.app'];
        yield 'missing team ID' => ['apns://keyId:@default?privateKey=dGVzdA%3D%3D&topic=com.example.app'];
        yield 'missing private key' => ['apns://keyId:teamId@default?topic=com.example.app'];
        yield 'missing topic' => ['apns://keyId:teamId@default?privateKey=dGVzdA%3D%3D'];
    }

    public static function unsupportedSchemeProvider(): iterable
    {
        yield ['somethingElse://keyId:teamId@default?privateKey=dGVzdA%3D%3D&topic=com.example.app'];
    }
}
