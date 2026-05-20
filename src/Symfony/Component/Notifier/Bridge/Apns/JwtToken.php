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

/**
 * Generates and caches ES256 JWT tokens for Apple Push Notification service authentication.
 *
 * @author Mathieu Santostefano <msantostefano@protonmail.com>
 *
 * @internal
 */
final class JwtToken
{
    private ?string $token = null;
    private ?float $generatedAt = null;

    /**
     * Tokens must not be refreshed more than once every 20 minutes
     * and expire after 60 minutes. We refresh at 50 minutes.
     */
    private const TOKEN_TTL = 3000;

    public function __construct(
        private string $keyId,
        private string $teamId,
        #[\SensitiveParameter] private string $privateKey,
    ) {
    }

    public function getToken(): string
    {
        if (null !== $this->token && null !== $this->generatedAt && (microtime(true) - $this->generatedAt) < self::TOKEN_TTL) {
            return $this->token;
        }

        $header = self::base64UrlEncode(json_encode([
            'alg' => 'ES256',
            'kid' => $this->keyId,
        ]));

        $claims = self::base64UrlEncode(json_encode([
            'iss' => $this->teamId,
            'iat' => time(),
        ]));

        $payload = $header.'.'.$claims;

        $key = openssl_pkey_get_private($this->privateKey);

        if (!$key) {
            throw new \InvalidArgumentException('Invalid APNs private key. Ensure the key is a valid PEM-encoded EC private key (.p8).');
        }

        if (!openssl_sign($payload, $signature, $key, \OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Failed to sign APNs JWT token.');
        }

        // OpenSSL returns DER-encoded signature, we need to convert to raw R+S format for JWT
        $signature = self::derToRaw($signature);

        $this->token = $payload.'.'.self::base64UrlEncode($signature);
        $this->generatedAt = microtime(true);

        return $this->token;
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Converts a DER-encoded ECDSA signature to the raw R+S format required by JWT.
     */
    private static function derToRaw(string $der): string
    {
        $offset = 2;

        // Read R
        $rLength = \ord($der[$offset + 1]);
        $r = substr($der, $offset + 2, $rLength);
        $offset += 2 + $rLength;

        // Read S
        $sLength = \ord($der[$offset + 1]);
        $s = substr($der, $offset + 2, $sLength);

        // Pad or trim R and S to 32 bytes each
        $r = str_pad(ltrim($r, "\0"), 32, "\0", \STR_PAD_LEFT);
        $s = str_pad(ltrim($s, "\0"), 32, "\0", \STR_PAD_LEFT);

        return $r.$s;
    }
}
