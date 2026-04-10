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
use Symfony\Component\Security\Http\Authenticator\Oidc\OidcClient;
use Symfony\Component\Security\Http\Authenticator\Oidc\OidcDiscovery;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class OidcClientTest extends TestCase
{
    private OidcDiscovery $discovery;
    private HttpClientInterface $httpClient;

    protected function setUp(): void
    {
        $this->discovery = $this->createMock(OidcDiscovery::class);
        $this->discovery->method('getConfiguration')->willReturn([
            'token_endpoint' => 'https://provider.example.com/token',
            'userinfo_endpoint' => 'https://provider.example.com/userinfo',
            'issuer' => 'https://provider.example.com',
        ]);

        $this->httpClient = $this->createMock(HttpClientInterface::class);
    }

    public function testExchangeCodeWithClientSecretPost()
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn([
            'access_token' => 'access-123',
            'id_token' => 'id-token-abc',
        ]);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with('POST', 'https://provider.example.com/token', $this->callback(function (array $options) {
                $this->assertSame('authorization_code', $options['body']['grant_type']);
                $this->assertSame('auth-code', $options['body']['code']);
                $this->assertSame('https://app.example.com/callback', $options['body']['redirect_uri']);
                $this->assertSame('test-client-id', $options['body']['client_id']);
                $this->assertSame('test-client-secret', $options['body']['client_secret']);
                $this->assertArrayNotHasKey('auth_basic', $options);

                return true;
            }))
            ->willReturn($response);

        $client = $this->createClient();
        $tokens = $client->exchangeCode('auth-code', 'https://app.example.com/callback');

        $this->assertSame('access-123', $tokens['access_token']);
        $this->assertSame('id-token-abc', $tokens['id_token']);
    }

    public function testExchangeCodeWithClientSecretBasic()
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn([
            'access_token' => 'access-123',
            'id_token' => 'id-token-abc',
        ]);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with('POST', 'https://provider.example.com/token', $this->callback(function (array $options) {
                $this->assertSame(['test-client-id', 'test-client-secret'], $options['auth_basic']);
                $this->assertArrayNotHasKey('client_secret', $options['body']);

                return true;
            }))
            ->willReturn($response);

        $client = $this->createClient(tokenEndpointAuthMethod: 'client_secret_basic');
        $client->exchangeCode('auth-code', 'https://app.example.com/callback');
    }

    public function testExchangeCodeWithCodeVerifier()
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn([
            'access_token' => 'access-123',
            'id_token' => 'id-token-abc',
        ]);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with('POST', 'https://provider.example.com/token', $this->callback(function (array $options) {
                $this->assertSame('my-code-verifier', $options['body']['code_verifier']);

                return true;
            }))
            ->willReturn($response);

        $client = $this->createClient();
        $client->exchangeCode('auth-code', 'https://app.example.com/callback', 'my-code-verifier');
    }

    public function testFetchUserInfo()
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn([
            'sub' => '123',
            'email' => 'test@example.com',
        ]);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with('GET', 'https://provider.example.com/userinfo', [
                'auth_bearer' => 'access-token',
            ])
            ->willReturn($response);

        $client = $this->createClient();
        $claims = $client->fetchUserInfo('access-token');

        $this->assertSame('123', $claims['sub']);
        $this->assertSame('test@example.com', $claims['email']);
    }

    public function testFetchUserInfoThrowsWhenEndpointMissing()
    {
        $discovery = $this->createMock(OidcDiscovery::class);
        $discovery->method('getConfiguration')->willReturn([
            'token_endpoint' => 'https://provider.example.com/token',
        ]);

        $client = new OidcClient($this->httpClient, $discovery, 'test-client-id', 'test-client-secret');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('userinfo endpoint');

        $client->fetchUserInfo('access-token');
    }

    public function testGetClientId()
    {
        $client = $this->createClient();
        $this->assertSame('test-client-id', $client->getClientId());
    }

    public function testGetDiscovery()
    {
        $client = $this->createClient();
        $this->assertSame($this->discovery, $client->getDiscovery());
    }

    private function createClient(string $tokenEndpointAuthMethod = 'client_secret_post'): OidcClient
    {
        return new OidcClient(
            httpClient: $this->httpClient,
            discovery: $this->discovery,
            clientId: 'test-client-id',
            clientSecret: 'test-client-secret',
            tokenEndpointAuthMethod: $tokenEndpointAuthMethod,
        );
    }
}
