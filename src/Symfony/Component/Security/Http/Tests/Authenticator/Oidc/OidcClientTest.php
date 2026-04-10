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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Oidc\OidcClient;
use Symfony\Component\Security\Http\Authenticator\Oidc\OidcDiscovery;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class OidcClientTest extends TestCase
{
    private OidcDiscovery $discovery;
    private RequestStack $requestStack;
    private HttpClientInterface $httpClient;

    protected function setUp(): void
    {
        $this->discovery = $this->createMock(OidcDiscovery::class);
        $this->discovery->method('getConfiguration')->willReturn([
            'authorization_endpoint' => 'https://provider.example.com/authorize',
            'token_endpoint' => 'https://provider.example.com/token',
            'issuer' => 'https://provider.example.com',
            'jwks_uri' => 'https://provider.example.com/jwks',
            'userinfo_endpoint' => 'https://provider.example.com/userinfo',
            'end_session_endpoint' => 'https://provider.example.com/logout',
        ]);

        $this->httpClient = $this->createMock(HttpClientInterface::class);

        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));

        $this->requestStack = new RequestStack();
        $this->requestStack->push($request);
    }

    public function testStartAuthorizationReturnsRedirectResponse()
    {
        $client = $this->createClient();

        $response = $client->startAuthorization();

        $this->assertSame(302, $response->getStatusCode());
        $location = $response->headers->get('Location');
        $this->assertStringStartsWith('https://provider.example.com/authorize?', $location);

        $params = [];
        parse_str(parse_url($location, \PHP_URL_QUERY), $params);

        $this->assertSame('code', $params['response_type']);
        $this->assertSame('test-client-id', $params['client_id']);
        $this->assertSame('https://app.example.com/oidc/callback', $params['redirect_uri']);
        $this->assertStringContainsString('openid', $params['scope']);
        $this->assertNotEmpty($params['state']);
        $this->assertNotEmpty($params['nonce']);
    }

    public function testStartAuthorizationEnforcesOpenidScope()
    {
        $client = $this->createClient(scopes: ['profile', 'email']);

        $response = $client->startAuthorization();
        $location = $response->headers->get('Location');
        $params = [];
        parse_str(parse_url($location, \PHP_URL_QUERY), $params);

        $this->assertStringContainsString('openid', $params['scope']);
    }

    public function testStartAuthorizationWithPkce()
    {
        $client = $this->createClient(pkceEnabled: true);

        $response = $client->startAuthorization();
        $location = $response->headers->get('Location');
        $params = [];
        parse_str(parse_url($location, \PHP_URL_QUERY), $params);

        $this->assertSame('S256', $params['code_challenge_method']);
        $this->assertNotEmpty($params['code_challenge']);
    }

    public function testStartAuthorizationWithoutPkce()
    {
        $client = $this->createClient(pkceEnabled: false);

        $response = $client->startAuthorization();
        $location = $response->headers->get('Location');
        $params = [];
        parse_str(parse_url($location, \PHP_URL_QUERY), $params);

        $this->assertArrayNotHasKey('code_challenge', $params);
        $this->assertArrayNotHasKey('code_challenge_method', $params);
    }

    public function testStartAuthorizationStoresStateInSession()
    {
        $client = $this->createClient();

        $response = $client->startAuthorization();
        $location = $response->headers->get('Location');
        $params = [];
        parse_str(parse_url($location, \PHP_URL_QUERY), $params);

        $session = $this->requestStack->getSession();
        $this->assertSame($params['state'], $session->get('_security.oidc_login.main.state'));
        $this->assertSame($params['nonce'], $session->get('_security.oidc_login.main.nonce'));
    }

    public function testHandleCallbackExchangesCodeForTokens()
    {
        $nonce = bin2hex(random_bytes(16));
        $session = $this->requestStack->getSession();
        $state = bin2hex(random_bytes(16));
        $session->set('_security.oidc_login.main.state', $state);
        $session->set('_security.oidc_login.main.nonce', $nonce);

        $idToken = $this->buildIdToken(['nonce' => $nonce]);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn([
            'access_token' => 'access-123',
            'id_token' => $idToken,
            'refresh_token' => 'refresh-789',
            'expires_in' => 3600,
        ]);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with('POST', 'https://provider.example.com/token', $this->callback(function (array $options) {
                $this->assertSame('authorization_code', $options['body']['grant_type']);
                $this->assertSame('auth-code-abc', $options['body']['code']);
                $this->assertSame('test-client-secret', $options['body']['client_secret']);

                return true;
            }))
            ->willReturn($response);

        $client = $this->createClient(pkceEnabled: false);
        $request = Request::create('/oidc/callback?code=auth-code-abc&state='.$state);

        $tokens = $client->handleCallback($request);

        $this->assertSame('access-123', $tokens['access_token']);
        $this->assertSame($idToken, $tokens['id_token']);
        $this->assertSame('refresh-789', $tokens['refresh_token']);
    }

    public function testHandleCallbackValidatesNonce()
    {
        $session = $this->requestStack->getSession();
        $state = bin2hex(random_bytes(16));
        $session->set('_security.oidc_login.main.state', $state);
        $session->set('_security.oidc_login.main.nonce', 'expected-nonce');

        $idToken = $this->buildIdToken(['nonce' => 'wrong-nonce']);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn([
            'access_token' => 'access-123',
            'id_token' => $idToken,
        ]);
        $this->httpClient->method('request')->willReturn($response);

        $client = $this->createClient(pkceEnabled: false);
        $request = Request::create('/oidc/callback?code=auth-code&state='.$state);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('nonce');

        $client->handleCallback($request);
    }

    public function testHandleCallbackValidatesIssuer()
    {
        $nonce = bin2hex(random_bytes(16));
        $session = $this->requestStack->getSession();
        $state = bin2hex(random_bytes(16));
        $session->set('_security.oidc_login.main.state', $state);
        $session->set('_security.oidc_login.main.nonce', $nonce);

        $idToken = $this->buildIdToken(['nonce' => $nonce, 'iss' => 'https://evil.example.com']);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn([
            'access_token' => 'access-123',
            'id_token' => $idToken,
        ]);
        $this->httpClient->method('request')->willReturn($response);

        $client = $this->createClient(pkceEnabled: false);
        $request = Request::create('/oidc/callback?code=auth-code&state='.$state);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('issuer');

        $client->handleCallback($request);
    }

    public function testHandleCallbackValidatesAudience()
    {
        $nonce = bin2hex(random_bytes(16));
        $session = $this->requestStack->getSession();
        $state = bin2hex(random_bytes(16));
        $session->set('_security.oidc_login.main.state', $state);
        $session->set('_security.oidc_login.main.nonce', $nonce);

        $idToken = $this->buildIdToken(['nonce' => $nonce, 'aud' => 'wrong-client-id']);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn([
            'access_token' => 'access-123',
            'id_token' => $idToken,
        ]);
        $this->httpClient->method('request')->willReturn($response);

        $client = $this->createClient(pkceEnabled: false);
        $request = Request::create('/oidc/callback?code=auth-code&state='.$state);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('audience');

        $client->handleCallback($request);
    }

    public function testHandleCallbackValidatesExpiration()
    {
        $nonce = bin2hex(random_bytes(16));
        $session = $this->requestStack->getSession();
        $state = bin2hex(random_bytes(16));
        $session->set('_security.oidc_login.main.state', $state);
        $session->set('_security.oidc_login.main.nonce', $nonce);

        $idToken = $this->buildIdToken(['nonce' => $nonce, 'exp' => time() - 3600]);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn([
            'access_token' => 'access-123',
            'id_token' => $idToken,
        ]);
        $this->httpClient->method('request')->willReturn($response);

        $client = $this->createClient(pkceEnabled: false);
        $request = Request::create('/oidc/callback?code=auth-code&state='.$state);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('expired');

        $client->handleCallback($request);
    }

    public function testHandleCallbackWithInvalidState()
    {
        $session = $this->requestStack->getSession();
        $session->set('_security.oidc_login.main.state', 'expected-state');

        $client = $this->createClient();
        $request = Request::create('/oidc/callback?code=auth-code&state=wrong-state');

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid OIDC state');

        $client->handleCallback($request);
    }

    public function testHandleCallbackWithProviderError()
    {
        $client = $this->createClient();
        $request = Request::create('/oidc/callback?error=access_denied&error_description=User+denied+access');

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('User denied access');

        $client->handleCallback($request);
    }

    public function testHandleCallbackClearsSessionState()
    {
        $nonce = bin2hex(random_bytes(16));
        $session = $this->requestStack->getSession();
        $state = bin2hex(random_bytes(16));
        $session->set('_security.oidc_login.main.state', $state);
        $session->set('_security.oidc_login.main.nonce', $nonce);

        $idToken = $this->buildIdToken(['nonce' => $nonce]);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn([
            'access_token' => 'access-123',
            'id_token' => $idToken,
        ]);
        $this->httpClient->method('request')->willReturn($response);

        $client = $this->createClient(pkceEnabled: false);
        $request = Request::create('/oidc/callback?code=auth-code&state='.$state);
        $client->handleCallback($request);

        $this->assertNull($session->get('_security.oidc_login.main.state'));
        $this->assertNull($session->get('_security.oidc_login.main.nonce'));
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

    public function testStartAuthorizationResolvesRelativeCallbackUrl()
    {
        $client = $this->createClient(callbackUrl: '/oidc/callback');

        $response = $client->startAuthorization();
        $location = $response->headers->get('Location');
        $params = [];
        parse_str(parse_url($location, \PHP_URL_QUERY), $params);

        $this->assertSame('http://localhost/oidc/callback', $params['redirect_uri']);
    }

    public function testBuildEndSessionUrl()
    {
        $client = $this->createClient();
        $url = $client->buildEndSessionUrl('my-id-token', 'https://app.example.com/');

        $this->assertStringStartsWith('https://provider.example.com/logout?', $url);
        $this->assertStringContainsString('id_token_hint=my-id-token', $url);
        $this->assertStringContainsString('post_logout_redirect_uri=', $url);
    }

    /**
     * Builds a fake JWT ID token with the given claims for testing.
     */
    private function buildIdToken(array $extraClaims = []): string
    {
        $header = rtrim(strtr(base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])), '+/', '-_'), '=');
        $claims = array_merge([
            'iss' => 'https://provider.example.com',
            'aud' => 'test-client-id',
            'sub' => 'user-42',
            'exp' => time() + 3600,
            'iat' => time(),
        ], $extraClaims);
        $payload = rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=');
        $signature = rtrim(strtr(base64_encode('fake-signature'), '+/', '-_'), '=');

        return $header.'.'.$payload.'.'.$signature;
    }

    private function createClient(bool $pkceEnabled = true, array $scopes = ['openid'], string $callbackUrl = 'https://app.example.com/oidc/callback'): OidcClient
    {
        return new OidcClient(
            httpClient: $this->httpClient,
            discovery: $this->discovery,
            requestStack: $this->requestStack,
            firewallName: 'main',
            clientId: 'test-client-id',
            clientSecret: 'test-client-secret',
            tokenEndpointAuthMethod: 'client_secret_post',
            callbackUrl: $callbackUrl,
            scopes: $scopes,
            pkceEnabled: $pkceEnabled,
        );
    }
}
