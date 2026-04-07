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

use Symfony\Component\Notifier\Exception\UnsupportedSchemeException;
use Symfony\Component\Notifier\Transport\AbstractTransportFactory;
use Symfony\Component\Notifier\Transport\Dsn;

/**
 * @author Mathieu Santostefano <msantostefano@protonmail.com>
 */
final class ApnsTransportFactory extends AbstractTransportFactory
{
    public function create(Dsn $dsn): ApnsTransport
    {
        $scheme = $dsn->getScheme();

        if (!\in_array($scheme, $this->getSupportedSchemes(), true)) {
            throw new UnsupportedSchemeException($dsn, 'apns', $this->getSupportedSchemes());
        }

        $keyId = $this->getUser($dsn);
        $teamId = $this->getPassword($dsn);
        $privateKey = $dsn->getRequiredOption('privateKey');
        $topic = $dsn->getRequiredOption('topic');
        $sandbox = 'apns-sandbox' === $scheme;
        $host = 'default' === $dsn->getHost() ? null : $dsn->getHost();
        $port = $dsn->getPort();

        return (new ApnsTransport($keyId, $teamId, $privateKey, $topic, $sandbox, $this->client, $this->dispatcher))->setHost($host)->setPort($port);
    }

    protected function getSupportedSchemes(): array
    {
        return ['apns', 'apns-sandbox'];
    }
}
