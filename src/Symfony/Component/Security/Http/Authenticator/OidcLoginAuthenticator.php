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
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\AccessToken\Oidc\OidcTrait;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Oidc\OidcClient;
use Symfony\Component\Security\Http\Authenticator\Oidc\OidcDiscovery;
use Symfony\Component\Security\Http\Authenticator\Oidc\OidcIdToken;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;
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
class OidcLoginAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface, InteractiveAuthenticatorInterface
{
    use OidcTrait;

    private array $options;

    public function __construct(
        private readonly HttpUtils $httpUtils,
        private readonly OidcClient $oidcClient,
        private readonly OidcDiscovery $discovery,
        private readonly string $clientId,
        private readonly AuthenticationSuccessHandlerInterface $successHandler,
        private readonly AuthenticationFailureHandlerInterface $failureHandler,
        array $options,
    ) {
        $this->options = array_merge([
            'check_path' => '/oidc/callback',
            'firewall_name' => 'main',
        ], $options);
    }

    public function supports(Request $request): bool
    {
        return $this->httpUtils->checkRequestPath($request, $this->options['check_path'])
            && ($request->query->has('code') || $request->query->has('error'));
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        $session = $request->getSession();
        $prefix = $this->getSessionPrefix();
        $discoveryConfig = $this->discovery->getConfiguration();

        $state = bin2hex(random_bytes(32));
        $nonce = bin2hex(random_bytes(32));
        $codeVerifier = $this->generateCodeVerifier();

        $session->set($prefix.'state', $state);
        $session->set($prefix.'nonce', $nonce);
        $session->set($prefix.'code_verifier', $codeVerifier);

        $params = [
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $this->httpUtils->generateUri($request, $this->options['check_path']),
            'scope' => 'openid',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ];

        $authorizationEndpoint = $discoveryConfig['authorization_endpoint'];
        $authorizationUrl = $authorizationEndpoint
            .(str_contains($authorizationEndpoint, '?') ? '&' : '?')
            .http_build_query($params, '', '&', \PHP_QUERY_RFC3986);

        return new RedirectResponse($authorizationUrl);
    }

    public function authenticate(Request $request): Passport
    {
        $session = $request->getSession();
        $prefix = $this->getSessionPrefix();

        $this->checkForProviderError($request);
        $this->validateState($request, $session->get($prefix.'state'));

        $code = $request->query->get('code');
        if (null === $code) {
            throw new AuthenticationException('Missing authorization code in OIDC callback.');
        }

        $storedNonce = $session->get($prefix.'nonce');
        $tokenData = $this->exchangeAuthorizationCode($request, $session, $code);

        $idTokenClaims = OidcIdToken::decode($tokenData['id_token']);
        OidcIdToken::validateClaims(
            $idTokenClaims,
            $this->discovery->getConfiguration()['issuer'] ?? '',
            $this->clientId,
            $storedNonce,
        );

        $claims = $this->fetchUserClaims($tokenData['access_token'], $idTokenClaims);

        $userLoader = new FallbackUserLoader(function () use ($claims) {
            $claims['user_identifier'] = $claims['sub'];

            return $this->createUser($claims);
        });

        $passport = new SelfValidatingPassport(
            new UserBadge($claims['sub'], $userLoader, $claims),
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

    public function isInteractive(): bool
    {
        return true;
    }

    private function checkForProviderError(Request $request): void
    {
        $error = $request->query->get('error');
        if (null !== $error) {
            $description = $request->query->get('error_description', $error);
            throw new AuthenticationException(\sprintf('OIDC provider returned an error: "%s"', $description));
        }
    }

    private function validateState(Request $request, mixed $storedState): void
    {
        $state = $request->query->get('state');
        // reject when no state was stored (empty session) so that an empty/forged
        // "state" cannot pass an empty === empty comparison (login CSRF)
        if (!\is_string($storedState) || '' === $storedState || null === $state || !hash_equals($storedState, $state)) {
            throw new AuthenticationException('Invalid OIDC state parameter.');
        }
    }

    /**
     * Exchanges the authorization code for tokens, clearing the one-time session
     * values, and ensures the token endpoint returned an ID and access token.
     *
     * @return array<string, mixed>
     */
    private function exchangeAuthorizationCode(Request $request, SessionInterface $session, string $code): array
    {
        $prefix = $this->getSessionPrefix();
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

        return $tokenData;
    }

    /**
     * Fetches user claims from the UserInfo endpoint and enforces the OIDC Core
     * 1.0, Section 5.3.2 rule that its "sub" matches the ID token "sub".
     *
     * @param array<string, mixed> $idTokenClaims
     *
     * @return array<string, mixed>
     */
    private function fetchUserClaims(string $accessToken, array $idTokenClaims): array
    {
        $claims = $this->oidcClient->fetchUserInfo($accessToken);

        if (!isset($claims['sub']) || '' === $claims['sub']) {
            throw new AuthenticationException('The "sub" claim was not found in the OIDC response.');
        }

        if (!isset($idTokenClaims['sub']) || !hash_equals((string) $idTokenClaims['sub'], (string) $claims['sub'])) {
            throw new AuthenticationException('The "sub" claim from the UserInfo endpoint does not match the ID token.');
        }

        return $claims;
    }

    private function generateCodeVerifier(): string
    {
        // A 32-byte (256-bit) verifier, hex-encoded to 64 characters, as recommended
        // by RFC 7636 Appendix B. The S256 challenge below is the only base64url step.
        return bin2hex(random_bytes(32));
    }

    private function getSessionPrefix(): string
    {
        return '_security.oidc_login.'.$this->options['firewall_name'].'.';
    }
}
