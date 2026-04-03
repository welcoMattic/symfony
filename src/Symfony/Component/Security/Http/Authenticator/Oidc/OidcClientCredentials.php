<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Security\Http\Authenticator\Oidc;

/**
 * Holds the client credentials used to authenticate with an OIDC provider.
 *
 * @author Mathieu Santostefano <msantostefano@proton.me>
 */
final class OidcClientCredentials
{
    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $tokenEndpointAuthMethod = 'client_secret_post',
    ) {
    }

    public function getClientId(): string
    {
        return $this->clientId;
    }

    public function getClientSecret(): string
    {
        return $this->clientSecret;
    }

    public function getTokenEndpointAuthMethod(): string
    {
        return $this->tokenEndpointAuthMethod;
    }
}
