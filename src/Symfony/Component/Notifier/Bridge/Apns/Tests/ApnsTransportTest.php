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

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Notifier\Bridge\Apns\ApnsOptions;
use Symfony\Component\Notifier\Bridge\Apns\ApnsTransport;
use Symfony\Component\Notifier\Exception\TransportException;
use Symfony\Component\Notifier\Message\PushMessage;
use Symfony\Component\Notifier\Message\SmsMessage;
use Symfony\Component\Notifier\Test\TransportTestCase;
use Symfony\Component\Notifier\Tests\Transport\DummyMessage;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * @author Mathieu Santostefano <msantostefano@protonmail.com>
 */
final class ApnsTransportTest extends TransportTestCase
{
    private static function getPrivateKey(): string
    {
        return 'LS0tLS1CRUdJTiBQUklWQVRFIEtFWS0tLS0tCk1JR0hBZ0VBTUJNR0J5cUdTTTQ5QWdFR0NDcUdTTTQ5QXdFSEJHMHdhd0lCQVFRZ2FubjBNQ0lpdVVLT0Zyck0KS2tWVnNpcnovSXIvcTk1dGo0NVV2NmZmZ2tlaFJBTkNBQVJXS1hBcXhVQ3dPTHJMY2MxQWQ1NDArOXNYekN5Wgo0NXQzMGpPM1FaNURBeWxwM3lSN3ZEUUtBUFB0eEMvaHY4WGZMeEU0TTNhUEZQTkY5WlBhczF0cAotLS0tLUVORCBQUklWQVRFIEtFWS0tLS0tCg==';
    }

    public static function createTransport(?HttpClientInterface $client = null): ApnsTransport
    {
        return new ApnsTransport('KEYID12345', 'TEAMID1234', self::getPrivateKey(), 'com.example.app', false, $client ?? new MockHttpClient());
    }

    public static function toStringProvider(): iterable
    {
        yield ['apns://KEYID12345:TEAMID1234@api.push.apple.com?topic=com.example.app', self::createTransport()];
    }

    public static function supportedMessagesProvider(): iterable
    {
        yield [new PushMessage('Hello!', 'Symfony Notifier')];
        yield [(new PushMessage('Hello!', 'Symfony Notifier'))->options(new ApnsOptions('device-token'))];
    }

    public static function unsupportedMessagesProvider(): iterable
    {
        yield [new SmsMessage('0670802161', 'Hello!')];
        yield [new DummyMessage()];
    }

    public function testSend(): void
    {
        $response = new MockResponse('', [
            'http_code' => 200,
            'response_headers' => ['apns-id' => 'a1b2c3d4-e5f6-7890-abcd-ef1234567890'],
        ]);

        $transport = self::createTransport(new MockHttpClient($response));

        $options = new ApnsOptions('device-token-123');
        $message = (new PushMessage('Test Title', 'Test Body'))->options($options);
        $sentMessage = $transport->send($message);

        $this->assertSame('a1b2c3d4-e5f6-7890-abcd-ef1234567890', $sentMessage->getMessageId());
    }

    public function testSendWithErrorResponse(): void
    {
        $response = new JsonMockResponse(['reason' => 'BadDeviceToken'], [
            'http_code' => 400,
        ]);

        $transport = self::createTransport(new MockHttpClient($response));

        $options = new ApnsOptions('invalid-token');
        $message = (new PushMessage('Test', 'Body'))->options($options);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Unable to send the Apple push notification: "BadDeviceToken" (HTTP 400).');

        $transport->send($message);
    }

    public function testSendThrowsWithoutRecipient(): void
    {
        $transport = self::createTransport();

        $message = new PushMessage('Test', 'Body');

        $this->expectException(\Symfony\Component\Notifier\Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('The "Symfony\Component\Notifier\Bridge\Apns\ApnsTransport" transport requires a device token as recipient ID.');

        $transport->send($message);
    }
}
