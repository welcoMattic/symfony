<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Security\Http\Authenticator;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\AccessToken\Oidc\OidcTrait;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Oidc\OidcClient;
use Symfony\Component\Security\Http\Authenticator\Oidc\OidcIdToken;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;
use Symfony\Component\Security\Http\HttpUtils;

/**
 * Authenticator for the OpenID Connect Authorization Code Flow.
 *
 * @see https://openid.net/specs/openid-connect-core-1_0.html#CodeFlowAuth
 *
 * @author Mathieu Santostefano <msantostefano@proton.me>
 *
 * @final
 */
class OidcLoginAuthenticator extends AbstractLoginFormAuthenticator
{
    use OidcTrait;

    private array $options;

    public function __construct(
        private readonly HttpUtils $httpUtils,
        private readonly OidcClient $oidcClient,
        private readonly AuthenticationSuccessHandlerInterface $successHandler,
        private readonly AuthenticationFailureHandlerInterface $failureHandler,
        array $options,
        private readonly array $authorizationParams = [],
    ) {
        $this->options = array_merge([
            'check_path' => '/oidc/callback',
            'login_path' => '/login',
            'direct_redirect' => false,
            'user_identifier_claim' => 'sub',
            'user_data_source' => 'userinfo',
            'firewall_name' => 'main',
            'scopes' => ['openid'],
            'pkce_enabled' => true,
            'pkce_method' => 'S256',
        ], $options);
    }

    protected function getLoginUrl(Request $request): string
    {
        return $this->httpUtils->generateUri($request, $this->options['login_path']);
    }

    public function supports(Request $request): bool
    {
        return $this->httpUtils->checkRequestPath($request, $this->options['check_path'])
            && ($request->query->has('code') || $request->query->has('error'));
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        if ($this->options['direct_redirect']) {
            return $this->startAuthorization($request);
        }

        return parent::start($request, $authException);
    }

    public function authenticate(Request $request): Passport
    {
        $session = $request->getSession();
        $prefix = $this->getSessionPrefix();

        // Check for provider error
        $error = $request->query->get('error');
        if (null !== $error) {
            $description = $request->query->get('error_description', $error);
            throw new AuthenticationException(\sprintf('OIDC provider returned an error: %s', $description));
        }

        // Validate state
        $state = $request->query->get('state');
        $storedState = $session->get($prefix.'state');
        if (null === $state || !hash_equals((string) $storedState, $state)) {
            throw new AuthenticationException('Invalid OIDC state parameter.');
        }

        // Get authorization code
        $code = $request->query->get('code');
        if (null === $code) {
            throw new AuthenticationException('Missing authorization code in OIDC callback.');
        }

        $storedNonce = $session->get($prefix.'nonce');
        $codeVerifier = $session->get($prefix.'code_verifier');
        $redirectUri = $this->httpUtils->generateUri($request, $this->options['check_path']);

        try {
            $tokenData = $this->oidcClient->exchangeCode($code, $redirectUri, $codeVerifier);
        } finally {
            $session->remove($prefix.'state');
            $session->remove($prefix.'nonce');
            $session->remove($prefix.'code_verifier');
        }

        if (!isset($tokenData['id_token'])) {
            throw new AuthenticationException('The token endpoint response does not contain an "id_token".');
        }
        if (!isset($tokenData['access_token'])) {
            throw new AuthenticationException('The token endpoint response does not contain an "access_token".');
        }

        // Validate ID token claims
        $discoveryConfig = $this->oidcClient->getDiscovery()->getConfiguration();
        OidcIdToken::validateClaims(
            OidcIdToken::decode($tokenData['id_token']),
            $discoveryConfig['issuer'] ?? '',
            $this->oidcClient->getClientId(),
            $storedNonce,
        );

        // Fetch user claims
        $claims = 'userinfo' === $this->options['user_data_source']
            ? $this->oidcClient->fetchUserInfo($tokenData['access_token'])
            : OidcIdToken::decode($tokenData['id_token']);

        $userIdentifierClaim = $this->options['user_identifier_claim'];
        if (!isset($claims[$userIdentifierClaim]) || '' === $claims[$userIdentifierClaim]) {
            throw new AuthenticationException(\sprintf('The "%s" claim was not found in the OIDC response.', $userIdentifierClaim));
        }

        $userIdentifier = $claims[$userIdentifierClaim];

        // A UserProvider can override FallbackUserLoader via UserProviderListener
        $userLoader = new FallbackUserLoader(function () use ($claims, $userIdentifierClaim) {
            $claims['user_identifier'] = $claims[$userIdentifierClaim];

            return $this->createUser($claims);
        });

        $passport = new SelfValidatingPassport(
            new UserBadge($userIdentifier, $userLoader, $claims),
            [new RememberMeBadge()],
        );

        $passport->setAttribute('oidc_token_data', $tokenData);

        return $passport;
    }

    public function createToken(Passport $passport, string $firewallName): TokenInterface
    {
        $token = new PostAuthenticationToken($passport->getUser(), $firewallName, $passport->getUser()->getRoles());

        $tokenData = $passport->getAttribute('oidc_token_data');
        if (\is_array($tokenData)) {
            $token->setAttribute('oidc_id_token', $tokenData['id_token'] ?? null);
            $token->setAttribute('oidc_access_token', $tokenData['access_token'] ?? null);
        }

        return $token;
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return $this->successHandler->onAuthenticationSuccess($request, $token);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return $this->failureHandler->onAuthenticationFailure($request, $exception);
    }

    private function startAuthorization(Request $request): RedirectResponse
    {
        $session = $request->getSession();
        $prefix = $this->getSessionPrefix();
        $discoveryConfig = $this->oidcClient->getDiscovery()->getConfiguration();

        $state = bin2hex(random_bytes(32));
        $nonce = bin2hex(random_bytes(32));

        $session->set($prefix.'state', $state);
        $session->set($prefix.'nonce', $nonce);

        // OIDC Core 1.0, Section 3.1.2.1: scope MUST contain "openid"
        $scopes = $this->options['scopes'];
        if (!\in_array('openid', $scopes, true)) {
            array_unshift($scopes, 'openid');
        }

        $params = [
            'response_type' => 'code',
            'client_id' => $this->oidcClient->getClientId(),
            'redirect_uri' => $this->httpUtils->generateUri($request, $this->options['check_path']),
            'scope' => implode(' ', $scopes),
            'state' => $state,
            'nonce' => $nonce,
        ];

        if ($this->options['pkce_enabled']) {
            $codeVerifier = $this->generateCodeVerifier();
            $session->set($prefix.'code_verifier', $codeVerifier);

            $params['code_challenge'] = $this->generateCodeChallenge($codeVerifier, $this->options['pkce_method']);
            $params['code_challenge_method'] = $this->options['pkce_method'];
        }

        $params = array_merge($params, $this->authorizationParams);

        $authorizationEndpoint = $discoveryConfig['authorization_endpoint'];
        $authorizationUrl = $authorizationEndpoint
            .(str_contains($authorizationEndpoint, '?') ? '&' : '?')
            .http_build_query($params, '', '&', \PHP_QUERY_RFC3986);

        return new RedirectResponse($authorizationUrl);
    }

    private function generateCodeVerifier(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
    }

    private function generateCodeChallenge(string $codeVerifier, string $method): string
    {
        if ('S256' === $method) {
            return rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
        }

        return $codeVerifier;
    }

    private function getSessionPrefix(): string
    {
        return '_security.oidc_login.'.$this->options['firewall_name'].'.';
    }
}
