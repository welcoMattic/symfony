<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Security\Http\Tests\Authenticator;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Oidc\OidcClient;
use Symfony\Component\Security\Http\Authenticator\Oidc\OidcDiscovery;
use Symfony\Component\Security\Http\Authenticator\OidcLoginAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\HttpUtils;

class OidcLoginAuthenticatorTest extends TestCase
{
    private OidcClient $oidcClient;
    private OidcDiscovery $discovery;
    private AuthenticationSuccessHandlerInterface $successHandler;
    private AuthenticationFailureHandlerInterface $failureHandler;

    protected function setUp(): void
    {
        $this->discovery = $this->createMock(OidcDiscovery::class);
        $this->discovery->method('getConfiguration')->willReturn([
            'authorization_endpoint' => 'https://provider.example.com/authorize',
            'token_endpoint' => 'https://provider.example.com/token',
            'issuer' => 'https://provider.example.com',
            'userinfo_endpoint' => 'https://provider.example.com/userinfo',
        ]);

        $this->oidcClient = $this->createMock(OidcClient::class);
        $this->oidcClient->method('getDiscovery')->willReturn($this->discovery);
        $this->oidcClient->method('getClientId')->willReturn('test-client-id');

        $this->successHandler = $this->createStub(AuthenticationSuccessHandlerInterface::class);
        $this->failureHandler = $this->createStub(AuthenticationFailureHandlerInterface::class);
    }

    public function testSupportsCallbackWithCode()
    {
        $authenticator = $this->createAuthenticator();

        $this->assertTrue($authenticator->supports(Request::create('/oidc/callback?code=abc&state=xyz')));
    }

    public function testSupportsCallbackWithError()
    {
        $authenticator = $this->createAuthenticator();

        $this->assertTrue($authenticator->supports(Request::create('/oidc/callback?error=access_denied')));
    }

    public function testDoesNotSupportWrongPath()
    {
        $authenticator = $this->createAuthenticator();

        $this->assertFalse($authenticator->supports(Request::create('/other-path?code=abc')));
    }

    public function testDoesNotSupportMissingParams()
    {
        $authenticator = $this->createAuthenticator();

        $this->assertFalse($authenticator->supports(Request::create('/oidc/callback')));
    }

    public function testAuthenticate()
    {
        $nonce = bin2hex(random_bytes(16));
        $state = bin2hex(random_bytes(16));
        $idToken = $this->buildIdToken(['nonce' => $nonce]);

        $this->oidcClient->expects($this->once())
            ->method('exchangeCode')
            ->willReturn([
                'access_token' => 'access-123',
                'id_token' => $idToken,
            ]);

        $this->oidcClient->expects($this->once())
            ->method('fetchUserInfo')
            ->with('access-123')
            ->willReturn([
                'sub' => 'user-42',
                'email' => 'test@example.com',
                'name' => 'Test User',
            ]);

        $authenticator = $this->createAuthenticator();
        $request = $this->createCallbackRequest($state, $nonce);

        $passport = $authenticator->authenticate($request);

        $this->assertInstanceOf(SelfValidatingPassport::class, $passport);
        $this->assertSame('user-42', $passport->getBadge(UserBadge::class)->getUserIdentifier());
        $this->assertTrue($passport->hasBadge(RememberMeBadge::class));
        $this->assertSame('access-123', $passport->getAttribute('oidc_token_data')['access_token']);
    }

    public function testAuthenticateWithIdTokenDataSource()
    {
        $nonce = bin2hex(random_bytes(16));
        $state = bin2hex(random_bytes(16));
        $idToken = $this->buildIdToken([
            'nonce' => $nonce,
            'sub' => 'user-42',
            'email' => 'test@example.com',
        ]);

        $this->oidcClient->expects($this->once())
            ->method('exchangeCode')
            ->willReturn([
                'access_token' => 'access-123',
                'id_token' => $idToken,
            ]);

        $this->oidcClient->expects($this->never())
            ->method('fetchUserInfo');

        $authenticator = $this->createAuthenticator(['user_data_source' => 'id_token']);
        $request = $this->createCallbackRequest($state, $nonce);

        $passport = $authenticator->authenticate($request);

        $this->assertSame('user-42', $passport->getBadge(UserBadge::class)->getUserIdentifier());
    }

    public function testAuthenticateWithInvalidState()
    {
        $authenticator = $this->createAuthenticator();
        $request = $this->createCallbackRequest('valid-state', 'nonce');
        $request->query->set('state', 'wrong-state');

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid OIDC state');

        $authenticator->authenticate($request);
    }

    public function testAuthenticateWithProviderError()
    {
        $authenticator = $this->createAuthenticator();
        $request = Request::create('/oidc/callback?error=access_denied&error_description=User+denied+access');
        $request->setSession(new Session(new MockArraySessionStorage()));

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('User denied access');

        $authenticator->authenticate($request);
    }

    public function testAuthenticateMissingClaim()
    {
        $nonce = bin2hex(random_bytes(16));
        $state = bin2hex(random_bytes(16));
        $idToken = $this->buildIdToken(['nonce' => $nonce]);

        $this->oidcClient->method('exchangeCode')->willReturn([
            'access_token' => 'access-123',
            'id_token' => $idToken,
        ]);
        $this->oidcClient->method('fetchUserInfo')->willReturn(['email' => 'test@example.com']);

        $authenticator = $this->createAuthenticator();
        $request = $this->createCallbackRequest($state, $nonce);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('"sub" claim');

        $authenticator->authenticate($request);
    }

    public function testAuthenticateMissingAccessToken()
    {
        $nonce = bin2hex(random_bytes(16));
        $state = bin2hex(random_bytes(16));
        $idToken = $this->buildIdToken(['nonce' => $nonce]);

        $this->oidcClient->method('exchangeCode')->willReturn([
            'id_token' => $idToken,
        ]);

        $authenticator = $this->createAuthenticator();
        $request = $this->createCallbackRequest($state, $nonce);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('access_token');

        $authenticator->authenticate($request);
    }

    public function testAuthenticateClearsSession()
    {
        $nonce = bin2hex(random_bytes(16));
        $state = bin2hex(random_bytes(16));
        $idToken = $this->buildIdToken(['nonce' => $nonce]);

        $this->oidcClient->method('exchangeCode')->willReturn([
            'access_token' => 'access-123',
            'id_token' => $idToken,
        ]);
        $this->oidcClient->method('fetchUserInfo')->willReturn(['sub' => 'user-42']);

        $authenticator = $this->createAuthenticator();
        $request = $this->createCallbackRequest($state, $nonce);
        $authenticator->authenticate($request);

        $session = $request->getSession();
        $this->assertNull($session->get('_security.oidc_login.main.state'));
        $this->assertNull($session->get('_security.oidc_login.main.nonce'));
        $this->assertNull($session->get('_security.oidc_login.main.code_verifier'));
    }

    public function testStartWithDirectRedirect()
    {
        $authenticator = $this->createAuthenticator(['direct_redirect' => true]);
        $request = Request::create('/protected');
        $request->setSession(new Session(new MockArraySessionStorage()));

        $response = $authenticator->start($request);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $location = $response->getTargetUrl();
        $this->assertStringStartsWith('https://provider.example.com/authorize?', $location);

        $params = [];
        parse_str(parse_url($location, \PHP_URL_QUERY), $params);
        $this->assertSame('code', $params['response_type']);
        $this->assertSame('test-client-id', $params['client_id']);
        $this->assertNotEmpty($params['state']);
        $this->assertNotEmpty($params['nonce']);
        $this->assertNotEmpty($params['code_challenge']);
        $this->assertSame('S256', $params['code_challenge_method']);
    }

    public function testStartWithoutDirectRedirect()
    {
        $authenticator = $this->createAuthenticator(['direct_redirect' => false]);
        $request = Request::create('/protected');

        $response = $authenticator->start($request);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/login', $response->getTargetUrl());
    }

    private function createCallbackRequest(string $state, string $nonce, ?string $codeVerifier = null): Request
    {
        $request = Request::create('/oidc/callback?code=auth-code&state='.$state);
        $session = new Session(new MockArraySessionStorage());
        $request->setSession($session);

        $prefix = '_security.oidc_login.main.';
        $session->set($prefix.'state', $state);
        $session->set($prefix.'nonce', $nonce);
        if (null !== $codeVerifier) {
            $session->set($prefix.'code_verifier', $codeVerifier);
        }

        return $request;
    }

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

    private function createAuthenticator(array $options = [], array $authorizationParams = []): OidcLoginAuthenticator
    {
        return new OidcLoginAuthenticator(
            new HttpUtils(),
            $this->oidcClient,
            $this->successHandler,
            $this->failureHandler,
            $options,
            $authorizationParams,
        );
    }
}
