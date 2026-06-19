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
     * Decodes the protected header of a JWT without signature verification.
     *
     * @return array<string, mixed> The decoded header
     *
     * @throws AuthenticationException If the token format is invalid
     */
    public static function decodeHeader(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (3 !== \count($parts)) {
            throw new AuthenticationException('Invalid ID token format.');
        }

        $header = base64_decode(strtr($parts[0], '-_', '+/'), true);
        if (false === $header) {
            throw new AuthenticationException('Unable to decode ID token header.');
        }

        try {
            $decoded = json_decode($header, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new AuthenticationException('Invalid ID token header.');
        }

        if (!\is_array($decoded)) {
            throw new AuthenticationException('Invalid ID token header.');
        }

        return $decoded;
    }

    /**
     * Computes the OIDC token hash (c_hash/at_hash) of a value for a given JWS algorithm.
     *
     * Per OIDC Core 1.0, Section 3.3.2.11, the hash is the base64url encoding of the
     * left-most half of the digest produced by the hash algorithm bound to the ID
     * token "alg" header (e.g. RS256/ES256/PS256 use SHA-256).
     *
     * @throws AuthenticationException If the algorithm is not supported for hashing
     */
    public static function computeTokenHash(string $value, string $alg): string
    {
        $size = (int) substr($alg, -3);
        if (!\in_array($size, [256, 384, 512], true)) {
            throw new AuthenticationException(\sprintf('Unsupported ID token algorithm "%s" for token hash validation.', $alg));
        }

        $digest = hash('sha'.$size, $value, true);
        $left = substr($digest, 0, \strlen($digest) >> 1);

        return rtrim(strtr(base64_encode($left), '+/', '-_'), '=');
    }

    /**
     * Validates ID token claims per OIDC Core 1.0, Section 3.1.3.7.
     *
     * @throws AuthenticationException If any claim validation fails
     */
    public static function validateClaims(array $claims, string $expectedIssuer, string $expectedAudience, ?string $expectedNonce = null, ?int $maxAge = null): void
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

        // Section 3.1.3.7, steps 4-5: with multiple audiences the azp claim is
        // required and, when present, MUST be the expected client_id.
        if (\count($aud) > 1 && !isset($claims['azp'])) {
            throw new AuthenticationException('ID token has multiple audiences but is missing the "azp" claim.');
        }
        if (isset($claims['azp']) && !hash_equals($expectedAudience, (string) $claims['azp'])) {
            throw new AuthenticationException('ID token "azp" claim does not match the expected client_id.');
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

        // Section 3.1.3.7, step 12: when max_age was requested, auth_time is
        // REQUIRED and the elapsed time since end-user authentication must not
        // exceed it, otherwise re-authentication is required.
        if (null !== $maxAge) {
            if (!isset($claims['auth_time']) || !is_numeric($claims['auth_time'])) {
                throw new AuthenticationException('ID token is missing the "auth_time" claim required when "max_age" is requested.');
            }

            if (time() - (int) $claims['auth_time'] > $maxAge) {
                throw new AuthenticationException('ID token "auth_time" exceeds the requested "max_age"; re-authentication is required.');
            }
        }
    }
}
