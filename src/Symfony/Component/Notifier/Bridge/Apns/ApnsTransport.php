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

use Symfony\Component\Notifier\Exception\InvalidArgumentException;
use Symfony\Component\Notifier\Exception\TransportException;
use Symfony\Component\Notifier\Exception\UnsupportedMessageTypeException;
use Symfony\Component\Notifier\Message\MessageInterface;
use Symfony\Component\Notifier\Message\PushMessage;
use Symfony\Component\Notifier\Message\SentMessage;
use Symfony\Component\Notifier\Transport\AbstractTransport;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * @author Mathieu Santostefano <msantostefano@protonmail.com>
 */
final class ApnsTransport extends AbstractTransport
{
    protected const HOST = 'api.push.apple.com';

    private const SANDBOX_HOST = 'api.sandbox.push.apple.com';

    private JwtToken $jwtToken;

    public function __construct(
        #[\SensitiveParameter] private string $keyId,
        #[\SensitiveParameter] private string $teamId,
        #[\SensitiveParameter] private string $privateKey,
        private string $topic,
        private bool $sandbox = false,
        ?HttpClientInterface $client = null,
        ?EventDispatcherInterface $dispatcher = null,
    ) {
        $this->jwtToken = new JwtToken($keyId, $teamId, base64_decode($privateKey));

        parent::__construct($client, $dispatcher);
    }

    protected function getDefaultHost(): string
    {
        return $this->sandbox ? self::SANDBOX_HOST : self::HOST;
    }

    public function __toString(): string
    {
        return \sprintf('%s://%s:%s@%s?topic=%s', $this->sandbox ? 'apns-sandbox' : 'apns', $this->keyId, $this->teamId, $this->getEndpoint(), $this->topic);
    }

    public function supports(MessageInterface $message): bool
    {
        return $message instanceof PushMessage && (null === $message->getOptions() || $message->getOptions() instanceof ApnsOptions);
    }

    /**
     * @see https://developer.apple.com/documentation/usernotifications/sending-notification-requests-to-apns
     */
    protected function doSend(MessageInterface $message): SentMessage
    {
        if (!$message instanceof PushMessage) {
            throw new UnsupportedMessageTypeException(__CLASS__, PushMessage::class, $message);
        }

        $options = $message->getOptions();

        if (null !== $options && !$options instanceof ApnsOptions) {
            throw new InvalidArgumentException(\sprintf('The "%s" transport only supports instances of "%s" for options.', __CLASS__, ApnsOptions::class));
        }

        $deviceToken = $options?->getRecipientId() ?? $message->getRecipientId();

        if (!$deviceToken) {
            throw new InvalidArgumentException(\sprintf('The "%s" transport requires a device token as recipient ID.', __CLASS__));
        }

        $pushType = $options?->getPushType() ?? 'alert';
        $priority = $options?->getPriority() ?? 10;

        // Build payload
        $payload = $options?->toArray() ?? [];
        $payload['aps'] ??= [];
        $payload['aps']['alert'] ??= [
            'title' => $message->getSubject(),
            'body' => $message->getContent(),
        ];

        $endpoint = \sprintf('https://%s/3/device/%s', $this->getEndpoint(), $deviceToken);

        $headers = [
            'authorization' => \sprintf('bearer %s', $this->jwtToken->getToken()),
            'apns-topic' => $this->topic,
            'apns-push-type' => $pushType,
            'apns-priority' => (string) $priority,
        ];

        if (null !== $options?->getExpiration()) {
            $headers['apns-expiration'] = (string) $options->getExpiration();
        }

        if (null !== $options?->getCollapseId()) {
            $headers['apns-collapse-id'] = $options->getCollapseId();
        }

        $response = $this->client->request('POST', $endpoint, [
            'headers' => $headers,
            'json' => $payload,
            'http_version' => '2.0',
        ]);

        try {
            $statusCode = $response->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            throw new TransportException('Could not reach the Apple Push Notification service.', $response, 0, $e);
        }

        if (200 !== $statusCode) {
            $result = $response->toArray(false);
            $reason = $result['reason'] ?? 'Unknown error';

            throw new TransportException(\sprintf('Unable to send the Apple push notification: "%s" (HTTP %d).', $reason, $statusCode), $response);
        }

        $apnsId = $response->getHeaders(false)['apns-id'][0] ?? null;

        $sentMessage = new SentMessage($message, (string) $this);

        if (null !== $apnsId) {
            $sentMessage->setMessageId($apnsId);
        }

        return $sentMessage;
    }
}
