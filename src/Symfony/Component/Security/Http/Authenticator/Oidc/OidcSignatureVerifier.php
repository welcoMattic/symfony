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

use Jose\Component\Checker;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWKSet;
use Jose\Component\Signature\Algorithm\ES256;
use Jose\Component\Signature\Algorithm\ES384;
use Jose\Component\Signature\Algorithm\ES512;
use Jose\Component\Signature\Algorithm\PS256;
use Jose\Component\Signature\Algorithm\PS384;
use Jose\Component\Signature\Algorithm\PS512;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\Algorithm\RS384;
use Jose\Component\Signature\Algorithm\RS512;
use Jose\Component\Signature\JWSTokenSupport;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Jose\Component\Signature\Serializer\JWSSerializerManager;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Verifies the JWS signature of an OIDC ID token against the provider's JWKS.
 *
 * The provider signing keys are discovered from the "jwks_uri" of the discovery
 * document and cached. Signature verification is mandatory whenever the ID token
 * is delivered through the user agent (implicit/hybrid flows) and recommended for
 * the Authorization Code Flow as well (OIDC Core 1.0, Section 3.1.3.7).
 *
 * @author Mathieu Santostefano <msantostefano@proton.me>
 */
final class OidcSignatureVerifier
{
    private const SIGNATURE_ALGORITHMS = [
        'RS256' => RS256::class,
        'RS384' => RS384::class,
        'RS512' => RS512::class,
        'ES256' => ES256::class,
        'ES384' => ES384::class,
        'ES512' => ES512::class,
        'PS256' => PS256::class,
        'PS384' => PS384::class,
        'PS512' => PS512::class,
    ];

    /**
     * @param list<string> $algorithms The signature algorithm names accepted to verify the ID token (e.g. ["RS256"])
     */
    public function __construct(
        private readonly array $algorithms,
        private readonly OidcDiscovery $discovery,
        private readonly CacheInterface $jwksCache,
        private readonly HttpClientInterface $httpClient,
        private readonly int $jwksCacheTtl = 3600,
    ) {
    }

    /**
     * Verifies the signature of the given ID token and returns its claims.
     *
     * @return array<string, mixed> The verified ID token claims
     *
     * @throws AuthenticationException If the signature cannot be verified
     */
    public function verify(string $idToken): array
    {
        if (!class_exists(JWSVerifier::class) || !class_exists(Checker\HeaderCheckerManager::class)) {
            throw new \LogicException('Verifying OIDC ID token signatures requires the "web-token/jwt-library" package. Try running "composer require web-token/jwt-library".');
        }

        $algorithmManager = new AlgorithmManager(array_map(function (string $name) {
            if (!isset(self::SIGNATURE_ALGORITHMS[$name])) {
                throw new \LogicException(\sprintf('Unsupported OIDC ID token signature algorithm "%s". Supported algorithms are: "%s".', $name, implode('", "', array_keys(self::SIGNATURE_ALGORITHMS))));
            }

            return new (self::SIGNATURE_ALGORITHMS[$name])();
        }, $this->algorithms));

        $jwksUri = $this->discovery->getConfiguration()['jwks_uri'] ?? null;
        if (!\is_string($jwksUri) || '' === $jwksUri) {
            throw new AuthenticationException('The OIDC provider does not expose a "jwks_uri" required to verify the ID token signature.');
        }

        $keys = $this->jwksCache->get(
            'oidc_jwks.'.hash('xxh128', $jwksUri),
            fn (ItemInterface $item): array => OidcJwks::fetchKeys($this->httpClient, $jwksUri, $item, $this->jwksCacheTtl),
        );
        $jwkset = JWKSet::createFromKeyData(['keys' => $keys]);

        try {
            $jws = (new JWSSerializerManager([new CompactSerializer()]))->unserialize($idToken);
        } catch (\InvalidArgumentException $e) {
            throw new AuthenticationException('The ID token is not a valid JWS.', 0, $e);
        }

        if (!(new JWSVerifier($algorithmManager))->verifyWithKeySet($jws, $jwkset, 0)) {
            throw new AuthenticationException('The ID token signature is invalid.');
        }

        // Ensures the "alg" header is one of the expected algorithms (prevents
        // algorithm-substitution attacks, e.g. "none").
        (new Checker\HeaderCheckerManager([new Checker\AlgorithmChecker($algorithmManager->list())], [new JWSTokenSupport()]))->check($jws, 0);

        $claims = json_decode($jws->getPayload() ?? '', true);
        if (!\is_array($claims)) {
            throw new AuthenticationException('The ID token payload is invalid.');
        }

        return $claims;
    }
}
