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
 * "plain" PKCE method: challenge = verifier.
 *
 * Only intended for clients that cannot compute SHA-256. Prefer {@see S256PkceMethod}.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc7636#section-4.2
 *
 * @author Mathieu Santostefano <msantostefano@proton.me>
 */
#[AsTaggedItem(index: 'plain')]
final class PlainPkceMethod implements PkceMethodInterface
{
    public static function getName(): string
    {
        return 'plain';
    }

    public function createChallenge(string $verifier): string
    {
        return $verifier;
    }
}
