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

use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Oidc\OidcIdToken;

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
        $this->expectExceptionMessage('issuer');

        OidcIdToken::validateClaims($claims, 'https://provider.example.com', 'my-client-id');
    }

    public function testValidateClaimsMissingIssuer()
    {
        $claims = [
            'aud' => 'my-client-id',
            'exp' => time() + 3600,
        ];

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('issuer');

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
        $this->expectExceptionMessage('audience');

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
        $this->expectExceptionMessage('expired');

        OidcIdToken::validateClaims($claims, 'https://provider.example.com', 'my-client-id');
    }

    public function testValidateClaimsMissingExp()
    {
        $claims = [
            'iss' => 'https://provider.example.com',
            'aud' => 'my-client-id',
        ];

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('expired');

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

    public function testValidateClaimsWithinMaxAge()
    {
        $claims = [
            'iss' => 'https://provider.example.com',
            'aud' => 'my-client-id',
            'exp' => time() + 3600,
            'auth_time' => time() - 30,
        ];

        OidcIdToken::validateClaims($claims, 'https://provider.example.com', 'my-client-id', null, 300);

        $this->addToAssertionCount(1);
    }

    public function testValidateClaimsExceedingMaxAge()
    {
        $claims = [
            'iss' => 'https://provider.example.com',
            'aud' => 'my-client-id',
            'exp' => time() + 3600,
            'auth_time' => time() - 600,
        ];

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('max_age');

        OidcIdToken::validateClaims($claims, 'https://provider.example.com', 'my-client-id', null, 300);
    }

    public function testValidateClaimsMissingAuthTimeWhenMaxAgeRequested()
    {
        $claims = [
            'iss' => 'https://provider.example.com',
            'aud' => 'my-client-id',
            'exp' => time() + 3600,
        ];

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('auth_time');

        OidcIdToken::validateClaims($claims, 'https://provider.example.com', 'my-client-id', null, 300);
    }

    public function testDecodeHeader()
    {
        $jwt = $this->buildJwt(['sub' => 'user-42']);

        $this->assertSame('RS256', OidcIdToken::decodeHeader($jwt)['alg']);
    }

    public function testDecodeHeaderInvalidFormat()
    {
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid ID token format');

        OidcIdToken::decodeHeader('not-a-jwt');
    }

    /**
     * Test vector from OIDC Core 1.0, Section 3.3.2.11 (at_hash example with RS256).
     */
    public function testComputeTokenHashMatchesSpecVector()
    {
        $accessToken = 'jHkWEdUXMU1BwAsC4vtUsZwnNvTIxEl0z9K3vx5KF0Y';

        $this->assertSame('77QmUPtjPfzWtF2AnpK9RQ', OidcIdToken::computeTokenHash($accessToken, 'RS256'));
    }

    public function testComputeTokenHashUnsupportedAlgorithm()
    {
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Unsupported ID token algorithm "none"');

        OidcIdToken::computeTokenHash('whatever', 'none');
    }

    private function buildJwt(array $claims = []): string
    {
        $header = rtrim(strtr(base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])), '+/', '-_'), '=');
        $payload = rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=');
        $signature = rtrim(strtr(base64_encode('fake-signature'), '+/', '-_'), '=');

        return $header.'.'.$payload.'.'.$signature;
    }
}
