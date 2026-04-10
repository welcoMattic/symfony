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

use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * Utility for decoding and validating OIDC ID tokens.
 *
 * Signature verification is not performed because the tokens are received
 * directly from the token endpoint over TLS (per OIDC Core 1.0, Section 3.1.3.7, item 6).
 *
 * @author Mathieu Santostefano <msantostefano@proton.me>
 */
final class OidcIdToken
{
    /**
     * Decodes the payload of a JWT without signature verification.
     *
     * @return array<string, mixed> The decoded claims
     *
     * @throws AuthenticationException If the token format is invalid
     */
    public static function decode(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (3 !== \count($parts)) {
            throw new AuthenticationException('Invalid ID token format.');
        }

        $payload = base64_decode(strtr($parts[1], '-_', '+/'), true);
        if (false === $payload) {
            throw new AuthenticationException('Unable to decode ID token payload.');
        }

        try {
            $claims = json_decode($payload, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new AuthenticationException('Invalid ID token payload.');
        }

        if (!\is_array($claims)) {
            throw new AuthenticationException('Invalid ID token payload.');
        }

        return $claims;
    }

    /**
     * Validates ID token claims per OIDC Core 1.0, Section 3.1.3.7.
     *
     * @throws AuthenticationException If any claim validation fails
     */
    public static function validateClaims(array $claims, string $expectedIssuer, string $expectedAudience, ?string $expectedNonce = null): void
    {
        // Section 3.1.3.7, step 2: iss MUST exactly match the issuer
        if (!isset($claims['iss']) || $claims['iss'] !== $expectedIssuer) {
            throw new AuthenticationException('ID token issuer does not match the expected issuer.');
        }

        // Section 3.1.3.7, step 3: aud MUST contain client_id
        $aud = $claims['aud'] ?? null;
        if (\is_string($aud)) {
            $aud = [$aud];
        }
        if (!\is_array($aud) || !\in_array($expectedAudience, $aud, true)) {
            throw new AuthenticationException('ID token audience does not contain the expected client_id.');
        }

        // Section 3.1.3.7, step 9: exp MUST not be passed
        if (!isset($claims['exp']) || !is_numeric($claims['exp']) || time() > (int) $claims['exp']) {
            throw new AuthenticationException('ID token has expired.');
        }

        // Section 3.1.3.7, step 11: nonce MUST match
        if (null !== $expectedNonce) {
            if (!isset($claims['nonce']) || !hash_equals($expectedNonce, $claims['nonce'])) {
                throw new AuthenticationException('ID token nonce does not match.');
            }
        }
    }
}
