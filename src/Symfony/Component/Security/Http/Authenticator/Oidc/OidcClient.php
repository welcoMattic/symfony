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

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Implements the OpenID Connect Authorization Code Flow protocol.
 *
 * @see https://openid.net/specs/openid-connect-core-1_0.html#CodeFlowAuth  OIDC Core 1.0 Section 3.1
 * @see https://datatracker.ietf.org/doc/html/rfc6749                       OAuth 2.0 (RFC 6749)
 * @see https://datatracker.ietf.org/doc/html/rfc7636                       PKCE (RFC 7636)
 * @see https://datatracker.ietf.org/doc/html/rfc9700                       OAuth 2.0 Security BCP (RFC 9700)
 *
 * @author Mathieu Santostefano <msantostefano@proton.me>
 */
final class OidcClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly OidcDiscovery $discovery,
        private readonly RequestStack $requestStack,
        private readonly string $firewallName,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $tokenEndpointAuthMethod,
        private readonly string $callbackUrl,
        private readonly array $scopes = ['openid'],
        private readonly bool $pkceEnabled = true,
        private readonly string $pkceMethod = 'S256',
    ) {
    }

    /**
     * Builds the authorization URL and returns a redirect response to the OIDC provider.
     *
     * @param array<string, string> $extraParams Additional query parameters for the authorization URL
     */
    public function startAuthorization(array $extraParams = []): RedirectResponse
    {
        $config = $this->discovery->getConfiguration();

        $state = bin2hex(random_bytes(32));
        $nonce = bin2hex(random_bytes(32));

        $this->setSessionValue('state', $state);
        $this->setSessionValue('nonce', $nonce);

        // OIDC Core 1.0, Section 3.1.2.1: scope MUST contain "openid"
        $scopes = $this->scopes;
        if (!\in_array('openid', $scopes, true)) {
            array_unshift($scopes, 'openid');
        }

        $params = [
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $this->resolveCallbackUrl(),
            'scope' => implode(' ', $scopes),
            'state' => $state,
            'nonce' => $nonce,
        ];

        if ($this->pkceEnabled) {
            $codeVerifier = rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
            $this->setSessionValue('code_verifier', $codeVerifier);

            if ('S256' === $this->pkceMethod) {
                $params['code_challenge'] = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
                $params['code_challenge_method'] = 'S256';
            } else {
                $params['code_challenge'] = $codeVerifier;
                $params['code_challenge_method'] = 'plain';
            }
        }

        $params = array_merge($params, $extraParams);

        $authorizationEndpoint = $config['authorization_endpoint'];
        $authorizationUrl = $authorizationEndpoint
            .(str_contains($authorizationEndpoint, '?') ? '&' : '?')
            .http_build_query($params, '', '&', \PHP_QUERY_RFC3986);

        return new RedirectResponse($authorizationUrl);
    }

    /**
     * Handles the callback from the OIDC provider by validating the state,
     * exchanging the authorization code for tokens, and validating the ID token claims.
     *
     * Validates per OIDC Core 1.0 Section 3.1.3.7:
     * - iss MUST match the provider issuer
     * - aud MUST contain the client_id
     * - exp MUST not be passed
     * - nonce MUST match the stored value (if sent)
     *
     * Signature verification is skipped as tokens come from the token endpoint over TLS
     * (per OIDC Core 1.0, Section 3.1.3.7, item 6).
     *
     * @return array{access_token: string, id_token: string, refresh_token?: string, expires_in?: int} The token endpoint response
     *
     * @throws AuthenticationException If state validation fails or token exchange fails
     */
    public function handleCallback(Request $request): array
    {
        $error = $request->query->get('error');
        if (null !== $error) {
            $description = $request->query->get('error_description', $error);
            throw new AuthenticationException(\sprintf('OIDC provider returned an error: %s', $description));
        }

        $state = $request->query->get('state');
        $storedState = $this->getSessionValue('state');

        if (null === $state || !hash_equals((string) $storedState, $state)) {
            throw new AuthenticationException('Invalid OIDC state parameter.');
        }

        $code = $request->query->get('code');
        if (null === $code) {
            throw new AuthenticationException('Missing authorization code in OIDC callback.');
        }

        $storedNonce = $this->getSessionValue('nonce');

        try {
            $tokenData = $this->exchangeCode($code);
        } finally {
            $this->clearSession();
        }

        if (!isset($tokenData['id_token'])) {
            throw new AuthenticationException('The token endpoint response does not contain an "id_token".');
        }

        $this->validateIdToken($tokenData['id_token'], $storedNonce);

        return $tokenData;
    }

    /**
     * Fetches the user's claims from the OIDC provider's UserInfo endpoint.
     *
     * @return array<string, mixed> The user's claims
     */
    public function fetchUserInfo(string $accessToken): array
    {
        $config = $this->discovery->getConfiguration();

        if (!isset($config['userinfo_endpoint'])) {
            throw new \LogicException('The OIDC provider does not expose a userinfo endpoint.');
        }

        return $this->httpClient->request('GET', $config['userinfo_endpoint'], [
            'auth_bearer' => $accessToken,
        ])->toArray();
    }

    /**
     * Builds the end-session (RP-Initiated Logout) URL.
     *
     * @see https://openid.net/specs/openid-connect-rpinitiated-1_0.html
     */
    public function buildEndSessionUrl(string $idToken, ?string $postLogoutRedirectUri = null): string
    {
        $config = $this->discovery->getConfiguration();

        if (!isset($config['end_session_endpoint'])) {
            throw new \LogicException('The OIDC provider does not expose an end_session_endpoint.');
        }

        $params = ['id_token_hint' => $idToken];

        if (null !== $postLogoutRedirectUri) {
            $params['post_logout_redirect_uri'] = $postLogoutRedirectUri;
        }

        return $config['end_session_endpoint'].'?'.http_build_query($params, '', '&', \PHP_QUERY_RFC3986);
    }

    /**
     * Validates the ID token claims per OIDC Core 1.0, Section 3.1.3.7.
     *
     * Signature verification is not performed here because the token was received
     * directly from the token endpoint over TLS.
     */
    private function validateIdToken(string $idToken, ?string $expectedNonce): void
    {
        $parts = explode('.', $idToken);
        if (3 !== \count($parts)) {
            throw new AuthenticationException('Invalid ID token format.');
        }

        $payload = base64_decode(strtr($parts[1], '-_', '+/'), true);
        if (false === $payload) {
            throw new AuthenticationException('Unable to decode ID token payload.');
        }

        try {
            $claims = json_decode($payload, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new AuthenticationException('Invalid ID token payload.');
        }

        if (!\is_array($claims)) {
            throw new AuthenticationException('Invalid ID token payload.');
        }

        $config = $this->discovery->getConfiguration();

        // Section 3.1.3.7, step 2: iss MUST exactly match the issuer
        if (!isset($claims['iss']) || $claims['iss'] !== ($config['issuer'] ?? null)) {
            throw new AuthenticationException('ID token issuer does not match the expected issuer.');
        }

        // Section 3.1.3.7, step 3: aud MUST contain client_id
        $aud = $claims['aud'] ?? null;
        if (\is_string($aud)) {
            $aud = [$aud];
        }
        if (!\is_array($aud) || !\in_array($this->clientId, $aud, true)) {
            throw new AuthenticationException('ID token audience does not contain the expected client_id.');
        }

        // Section 3.1.3.7, step 9: exp MUST not be passed
        if (!isset($claims['exp']) || !is_numeric($claims['exp']) || time() > (int) $claims['exp']) {
            throw new AuthenticationException('ID token has expired.');
        }

        // Section 3.1.3.7, step 11: nonce MUST match
        if (null !== $expectedNonce) {
            if (!isset($claims['nonce']) || !hash_equals($expectedNonce, $claims['nonce'])) {
                throw new AuthenticationException('ID token nonce does not match.');
            }
        }
    }

    private function exchangeCode(string $code): array
    {
        $config = $this->discovery->getConfiguration();

        $body = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->resolveCallbackUrl(),
            'client_id' => $this->clientId,
        ];

        $codeVerifier = $this->getSessionValue('code_verifier');
        if (null !== $codeVerifier) {
            $body['code_verifier'] = $codeVerifier;
        }

        $options = $this->buildTokenRequestOptions($body);

        return $this->httpClient->request('POST', $config['token_endpoint'], $options)->toArray();
    }

    /**
     * @param array<string, string> $body
     *
     * @return array<string, mixed>
     */
    private function buildTokenRequestOptions(array $body): array
    {
        if ('client_secret_basic' === $this->tokenEndpointAuthMethod) {
            return [
                'auth_basic' => [$this->clientId, $this->clientSecret],
                'body' => $body,
            ];
        }

        // Default: client_secret_post
        $body['client_secret'] = $this->clientSecret;

        return ['body' => $body];
    }

    /**
     * Resolves the callback URL to a full URL if it is a relative path.
     */
    private function resolveCallbackUrl(): string
    {
        if (str_starts_with($this->callbackUrl, 'http://') || str_starts_with($this->callbackUrl, 'https://')) {
            return $this->callbackUrl;
        }

        $request = $this->requestStack->getCurrentRequest();
        if (null === $request) {
            throw new \LogicException('Cannot resolve the OIDC callback URL without a current request.');
        }

        return $request->getSchemeAndHttpHost().$this->callbackUrl;
    }

    private function setSessionValue(string $key, string $value): void
    {
        $this->requestStack->getSession()->set('_security.oidc_login.'.$this->firewallName.'.'.$key, $value);
    }

    private function getSessionValue(string $key): ?string
    {
        return $this->requestStack->getSession()->get('_security.oidc_login.'.$this->firewallName.'.'.$key);
    }

    private function clearSession(): void
    {
        $session = $this->requestStack->getSession();
        $prefix = '_security.oidc_login.'.$this->firewallName.'.';

        $session->remove($prefix.'state');
        $session->remove($prefix.'nonce');
        $session->remove($prefix.'code_verifier');
    }
}
