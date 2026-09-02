<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Security\Http\EventListener;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Security\Http\Event\LogoutEvent;
use Symfony\Component\Security\Http\HttpUtils;
use Symfony\Component\Security\Http\Oidc\OidcDiscovery;

/**
 * Handles RP-Initiated Logout by redirecting to the OIDC provider's
 * end_session_endpoint on logout.
 *
 * @see https://openid.net/specs/openid-connect-rpinitiated-1_0.html
 *
 * @author Mathieu Santostefano <msantostefano@proton.me>
 */
final class OidcEndSessionListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly OidcDiscovery $discovery,
        private readonly HttpUtils $httpUtils,
        private readonly ?string $postLogoutRedirectPath = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function onLogout(LogoutEvent $event): void
    {
        $token = $event->getToken();
        if (null === $token || !$token->hasAttribute('oidc_id_token')) {
            return;
        }

        $idToken = $token->getAttribute('oidc_id_token');
        if (!\is_string($idToken)) {
            return;
        }

        try {
            $endSessionEndpoint = $this->discovery->getSecureEndpoint('end_session_endpoint');
        } catch (\RuntimeException $e) {
            $this->logger?->warning('The OIDC provider does not expose a secure "end_session_endpoint"; falling back to a local logout.', ['exception' => $e]);

            return;
        }

        $config = $this->discovery->getConfiguration();

        $params = ['id_token_hint' => $idToken];

        if (null !== $this->postLogoutRedirectPath) {
            $params['post_logout_redirect_uri'] = $this->httpUtils->generateUri($event->getRequest(), $this->postLogoutRedirectPath);
        }

        $endSessionUrl = $endSessionEndpoint.'?'.http_build_query($params, '', '&', \PHP_QUERY_RFC3986);
        $event->setResponse(new RedirectResponse($endSessionUrl));
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LogoutEvent::class => 'onLogout',
        ];
    }
}
