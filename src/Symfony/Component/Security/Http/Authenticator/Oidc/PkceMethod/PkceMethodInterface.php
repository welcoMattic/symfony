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

/**
 * Implements a PKCE code challenge method for the Authorization Code Flow.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc7636 Proof Key for Code Exchange (RFC 7636)
 *
 * @author Mathieu Santostefano <msantostefano@proton.me>
 */
interface PkceMethodInterface
{
    /**
     * Returns the PKCE method name, as sent in the "code_challenge_method" parameter.
     *
     * RFC 7636 defines two values: "plain" and "S256".
     */
    public static function getName(): string;

    /**
     * Derives the code challenge from the given code verifier.
     */
    public function createChallenge(string $verifier): string;
}
