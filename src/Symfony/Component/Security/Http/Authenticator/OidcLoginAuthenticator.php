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

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\AccessToken\Oidc\OidcTrait;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Oidc\OidcClient;
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
    ) {
        $this->options = array_merge([
            'check_path' => '/oidc/callback',
            'login_path' => '/login',
            'direct_redirect' => false,
            'claim' => 'sub',
            'enable_userinfo' => true,
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
            return $this->oidcClient->startAuthorization();
        }

        return parent::start($request, $authException);
    }

    public function authenticate(Request $request): Passport
    {
        $tokenData = $this->oidcClient->handleCallback($request);

        $claims = $this->options['enable_userinfo']
            ? $this->oidcClient->fetchUserInfo($tokenData['access_token'])
            : $this->decodeIdTokenClaims($tokenData['id_token']);

        $claim = $this->options['claim'];
        if (empty($claims[$claim])) {
            throw new AuthenticationException(\sprintf('The "%s" claim was not found in the OIDC response.', $claim));
        }

        $userIdentifier = $claims[$claim];

        // FallbackUserLoader can be overridden by a UserProvider via UserProviderListener
        $userLoader = new FallbackUserLoader(function () use ($claims, $claim) {
            $claims['user_identifier'] = $claims[$claim];

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

    /**
     * Decodes the ID token payload without signature verification.
     *
     * This is acceptable when the tokens come directly from the token endpoint
     * over TLS (per OpenID Connect Core 1.0, section 3.1.3.7).
     *
     * @return array<string, mixed>
     */
    private function decodeIdTokenClaims(string $idToken): array
    {
        $parts = explode('.', $idToken);
        if (3 !== \count($parts)) {
            throw new AuthenticationException('Invalid ID token format.');
        }

        $payload = base64_decode(strtr($parts[1], '-_', '+/'), true);
        if (false === $payload) {
            throw new AuthenticationException('Unable to decode ID token payload.');
        }

        $claims = json_decode($payload, true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($claims)) {
            throw new AuthenticationException('Invalid ID token payload.');
        }

        return $claims;
    }
}
