<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Security\Http\Tests\Authenticator\Oidc;

use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\Signature\Algorithm\ES256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Oidc\OidcDiscovery;
use Symfony\Component\Security\Http\Authenticator\Oidc\OidcSignatureVerifier;

#[RequiresPhpExtension('openssl')]
class OidcSignatureVerifierTest extends TestCase
{
    private const PUBLIC_JWK = [
        'kty' => 'EC',
        'crv' => 'P-256',
        'x' => '0QEAsI1wGI-dmYatdUZoWSRWggLEpyzopuhwk-YUnA4',
        'y' => 'KYl-qyZ26HobuYwlQh-r0iHX61thfP82qqEku7i0woo',
        'use' => 'sig',
        'alg' => 'ES256',
    ];

    public function testVerifyReturnsClaimsForAValidSignature()
    {
        $claims = ['iss' => 'https://provider.example.com', 'sub' => 'user-42'];
        $verifier = $this->createVerifier(['keys' => [self::PUBLIC_JWK]]);

        $this->assertSame($claims, $verifier->verify($this->buildJws(json_encode($claims))));
    }

    public function testVerifyRejectsATamperedSignature()
    {
        $verifier = $this->createVerifier(['keys' => [self::PUBLIC_JWK]]);

        // Keep a valid JWS structure but swap in a signature from another payload.
        [$header, $payload] = explode('.', $this->buildJws(json_encode(['sub' => 'user-42'])));
        [, , $foreignSignature] = explode('.', $this->buildJws(json_encode(['sub' => 'someone-else'])));
        $tampered = $header.'.'.$payload.'.'.$foreignSignature;

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('signature is invalid');

        $verifier->verify($tampered);
    }

    public function testVerifyRejectsAnUnknownSigningKey()
    {
        // A JWKS that exposes a valid key, but not the one used to sign the token.
        $verifier = $this->createVerifier(['keys' => [[
            'kty' => 'EC',
            'crv' => 'P-256',
            'x' => 'N1aUu8Pd2WdClkpCQ4QCPnGjYe_bTmDgEaSoxy5LhTw',
            'y' => 'Yr1v-tCNxE8QgAGlartrJAi343bI8VlAaNvgCOp8Azs',
            'use' => 'sig',
            'alg' => 'ES256',
        ]]]);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('signature is invalid');

        $verifier->verify($this->buildJws(json_encode(['sub' => 'user-42'])));
    }

    public function testVerifyFailsWhenProviderHasNoJwksUri()
    {
        $discovery = $this->createMock(OidcDiscovery::class);
        $discovery->method('getConfiguration')->willReturn(['issuer' => 'https://provider.example.com']);

        $verifier = new OidcSignatureVerifier(['ES256'], $discovery, new ArrayAdapter(), new MockHttpClient());

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('jwks_uri');

        $verifier->verify($this->buildJws(json_encode(['sub' => 'user-42'])));
    }

    public function testVerifyRejectsUnsupportedAlgorithm()
    {
        $verifier = new OidcSignatureVerifier(['HS256'], $this->createMock(OidcDiscovery::class), new ArrayAdapter(), new MockHttpClient());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Unsupported OIDC ID token signature algorithm "HS256"');

        $verifier->verify($this->buildJws(json_encode(['sub' => 'user-42'])));
    }

    private function createVerifier(array $jwkSet): OidcSignatureVerifier
    {
        $discovery = $this->createMock(OidcDiscovery::class);
        $discovery->method('getConfiguration')->willReturn([
            'issuer' => 'https://provider.example.com',
            'jwks_uri' => 'https://provider.example.com/jwks',
        ]);

        $httpClient = new MockHttpClient(new JsonMockResponse($jwkSet));

        return new OidcSignatureVerifier(['ES256'], $discovery, new ArrayAdapter(), $httpClient);
    }

    private function buildJws(string $payload): string
    {
        $jwk = new JWK([
            'kty' => 'EC',
            'crv' => 'P-256',
            'x' => '0QEAsI1wGI-dmYatdUZoWSRWggLEpyzopuhwk-YUnA4',
            'y' => 'KYl-qyZ26HobuYwlQh-r0iHX61thfP82qqEku7i0woo',
            'd' => 'iA_TV2zvftni_9aFAQwFO_9aypfJFCSpcCyevDvz220',
        ]);

        return (new CompactSerializer())->serialize(
            (new JWSBuilder(new AlgorithmManager([new ES256()])))->create()
                ->withPayload($payload)
                ->addSignature($jwk, ['alg' => 'ES256'])
                ->build()
        );
    }
}
