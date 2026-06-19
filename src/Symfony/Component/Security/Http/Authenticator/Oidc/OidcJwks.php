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

use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Fetches OpenID Connect signing keys (JWKS) and derives their cache lifetime
 * from the provider's HTTP cache headers.
 *
 * @author Mathieu Santostefano <msantostefano@proton.me>
 *
 * @internal
 */
final class OidcJwks
{
    /**
     * Cap the cache lifetime to 30 days to avoid keeping JWKS indefinitely.
     */
    private const MAX_TTL = 30 * 24 * 60 * 60;

    /**
     * Extracts the signing keys ("use": "sig") from a JWKS endpoint response,
     * together with the TTL (in seconds) advertised by the provider via
     * "Cache-Control: max-age" or "Expires", or null when none is advertised.
     *
     * @return array{0: list<array<string, mixed>>, 1: int|null}
     */
    public static function fromResponse(ResponseInterface $response): array
    {
        $headers = $response->getHeaders();

        $ttl = null;
        if (preg_match('/max-age=(\d+)/', $headers['cache-control'][0] ?? '', $m)) {
            $ttl = (int) $m[1];
        } elseif (0 < $expires = strtotime($headers['expires'][0] ?? '@0') - time()) {
            $ttl = $expires;
        }

        $keys = [];
        foreach ($response->toArray()['keys'] ?? [] as $key) {
            if ('sig' === ($key['use'] ?? null)) {
                $keys[] = $key;
            }
        }

        return [$keys, $ttl];
    }

    /**
     * Fetches the provider signing keys from the given JWKS URI and adjusts the
     * cache item lifetime from the response headers (capped at 30 days).
     *
     * @return list<array<string, mixed>>
     */
    public static function fetchKeys(HttpClientInterface $httpClient, string $jwksUri, ItemInterface $item, int $defaultTtl = 3600): array
    {
        [$keys, $ttl] = self::fromResponse($httpClient->request('GET', $jwksUri));

        $item->expiresAfter(null === $ttl ? $defaultTtl : min($ttl, self::MAX_TTL));

        return $keys;
    }
}
