<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Security\Http\Authenticator\Oidc\PkceMethod;

use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * "S256" PKCE method: challenge = BASE64URL(SHA256(verifier)).
 *
 * @see https://datatracker.ietf.org/doc/html/rfc7636#section-4.2
 *
 * @author Mathieu Santostefano <msantostefano@proton.me>
 */
#[AsTaggedItem(index: 'S256')]
final class S256PkceMethod implements PkceMethodInterface
{
    public static function getName(): string
    {
        return 'S256';
    }

    public function createChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }
}
