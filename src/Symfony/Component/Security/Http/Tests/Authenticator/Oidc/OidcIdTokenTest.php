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
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Oidc\OidcIdToken;

#[RequiresPhpExtension('openssl')]
class OidcIdTokenTest extends TestCase
{
    public function testDecode()
    {
        $jwt = $this->buildJwt(['sub' => 'user-42', 'email' => 'test@example.com']);

        $claims = OidcIdToken::decode($jwt);

        $this->assertSame('user-42', $claims['sub']);
        $this->assertSame('test@example.com', $claims['email']);
    }

    public function testDecodeInvalidFormat()
    {
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid ID token format');

        OidcIdToken::decode('not-a-jwt');
    }

    public function testDecodeInvalidBase64()
    {
        $this->expectException(AuthenticationException::class);

        OidcIdToken::decode('header.!!!invalid!!!.signature');
    }

    public function testDecodeInvalidJson()
    {
        $header = base64_encode('{"alg":"RS256"}');
        $payload = rtrim(strtr(base64_encode('not-json'), '+/', '-_'), '=');
        $signature = base64_encode('sig');

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid ID token payload');

        OidcIdToken::decode($header.'.'.$payload.'.'.$signature);
    }

    public function testValidateClaims()
    {
        $claims = [
            'iss' => 'https://provider.example.com',
            'aud' => 'my-client-id',
            'exp' => time() + 3600,
            'nonce' => 'expected-nonce',
        ];

        OidcIdToken::validateClaims($claims, 'https://provider.example.com', 'my-client-id', 'expected-nonce');

        $this->addToAssertionCount(1);
    }

    public function testValidateClaimsWithArrayAudience()
    {
        $claims = [
            'iss' => 'https://provider.example.com',
            'aud' => ['other-client', 'my-client-id'],
            'azp' => 'my-client-id',
            'exp' => time() + 3600,
        ];

        OidcIdToken::validateClaims($claims, 'https://provider.example.com', 'my-client-id');

        $this->addToAssertionCount(1);
    }

    public function testValidateClaimsMultipleAudienceMissingAzp()
    {
        $claims = [
            'iss' => 'https://provider.example.com',
            'aud' => ['other-client', 'my-client-id'],
            'exp' => time() + 3600,
        ];

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('azp');

        OidcIdToken::validateClaims($claims, 'https://provider.example.com', 'my-client-id');
    }

    public function testValidateClaimsWrongAzp()
    {
        $claims = [
            'iss' => 'https://provider.example.com',
            'aud' => 'my-client-id',
            'azp' => 'another-client',
            'exp' => time() + 3600,
        ];

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('azp');

        OidcIdToken::validateClaims($claims, 'https://provider.example.com', 'my-client-id');
    }

    public function testValidateClaimsWithoutNonce()
    {
        $claims = [
            'iss' => 'https://provider.example.com',
            'aud' => 'my-client-id',
            'exp' => time() + 3600,
        ];

        OidcIdToken::validateClaims($claims, 'https://provider.example.com', 'my-client-id');

        $this->addToAssertionCount(1);
    }

    public function testValidateClaimsWrongIssuer()
    {
        $claims = [
            'iss' => 'https://evil.example.com',
            'aud' => 'my-client-id',
            'exp' => time() + 3600,
        ];

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid ID token');

        OidcIdToken::validateClaims($claims, 'https://provider.example.com', 'my-client-id');
    }

    public function testValidateClaimsMissingIssuer()
    {
        $claims = [
            'aud' => 'my-client-id',
            'exp' => time() + 3600,
        ];

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid ID token');

        OidcIdToken::validateClaims($claims, 'https://provider.example.com', 'my-client-id');
    }

    public function testValidateClaimsWrongAudience()
    {
        $claims = [
            'iss' => 'https://provider.example.com',
            'aud' => 'wrong-client-id',
            'exp' => time() + 3600,
        ];

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid ID token');

        OidcIdToken::validateClaims($claims, 'https://provider.example.com', 'my-client-id');
    }

    public function testValidateClaimsExpired()
    {
        $claims = [
            'iss' => 'https://provider.example.com',
            'aud' => 'my-client-id',
            'exp' => time() - 3600,
        ];

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid ID token');

        OidcIdToken::validateClaims($claims, 'https://provider.example.com', 'my-client-id');
    }

    public function testValidateClaimsMissingExp()
    {
        $claims = [
            'iss' => 'https://provider.example.com',
            'aud' => 'my-client-id',
        ];

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid ID token');

        OidcIdToken::validateClaims($claims, 'https://provider.example.com', 'my-client-id');
    }

    public function testValidateClaimsWrongNonce()
    {
        $claims = [
            'iss' => 'https://provider.example.com',
            'aud' => 'my-client-id',
            'exp' => time() + 3600,
            'nonce' => 'wrong-nonce',
        ];

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('nonce');

        OidcIdToken::validateClaims($claims, 'https://provider.example.com', 'my-client-id', 'expected-nonce');
    }

    public function testValidateClaimsMissingNonceWhenExpected()
    {
        $claims = [
            'iss' => 'https://provider.example.com',
            'aud' => 'my-client-id',
            'exp' => time() + 3600,
        ];

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('nonce');

        OidcIdToken::validateClaims($claims, 'https://provider.example.com', 'my-client-id', 'expected-nonce');
    }

    private function buildJwt(array $claims = []): string
    {
        return (new CompactSerializer())->serialize(
            (new JWSBuilder(new AlgorithmManager([new ES256()])))
                ->create()
                ->withPayload(json_encode($claims))
                ->addSignature(self::getJWK(), ['alg' => 'ES256'])
                ->build()
        );
    }

    private static function getJWK(): JWK
    {
        // tip: use https://mkjwk.org/ to generate a JWK
        return new JWK([
            'kty' => 'EC',
            'crv' => 'P-256',
            'x' => '0QEAsI1wGI-dmYatdUZoWSRWggLEpyzopuhwk-YUnA4',
            'y' => 'KYl-qyZ26HobuYwlQh-r0iHX61thfP82qqEku7i0woo',
            'd' => 'iA_TV2zvftni_9aFAQwFO_9aypfJFCSpcCyevDvz220',
        ]);
    }
}
