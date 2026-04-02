<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Security\Http\Tests\EventListener;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authenticator\Oidc\OidcClient;
use Symfony\Component\Security\Http\Event\LogoutEvent;
use Symfony\Component\Security\Http\EventListener\OidcEndSessionListener;
use Symfony\Component\Security\Http\HttpUtils;

class OidcEndSessionListenerTest extends TestCase
{
    public function testOnLogoutRedirectsToEndSessionEndpoint()
    {
        $oidcClient = $this->createMock(OidcClient::class);
        $oidcClient->expects($this->once())
            ->method('buildEndSessionUrl')
            ->with('my-id-token', 'http://localhost/')
            ->willReturn('https://provider.example.com/logout?id_token_hint=my-id-token');

        $listener = new OidcEndSessionListener($oidcClient, new HttpUtils(), '/');

        $token = $this->createMock(TokenInterface::class);
        $token->method('hasAttribute')->with('oidc_id_token')->willReturn(true);
        $token->method('getAttribute')->with('oidc_id_token')->willReturn('my-id-token');

        $event = new LogoutEvent(Request::create('/logout'), $token);
        $listener->onLogout($event);

        $response = $event->getResponse();
        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertStringStartsWith('https://provider.example.com/logout', $response->getTargetUrl());
    }

    public function testOnLogoutDoesNothingWithoutOidcIdToken()
    {
        $oidcClient = $this->createMock(OidcClient::class);
        $oidcClient->expects($this->never())->method('buildEndSessionUrl');

        $listener = new OidcEndSessionListener($oidcClient, new HttpUtils(), '/');

        $token = $this->createMock(TokenInterface::class);
        $token->method('hasAttribute')->with('oidc_id_token')->willReturn(false);

        $event = new LogoutEvent(Request::create('/logout'), $token);
        $listener->onLogout($event);

        $this->assertNull($event->getResponse());
    }

    public function testOnLogoutDoesNothingWithoutToken()
    {
        $oidcClient = $this->createMock(OidcClient::class);
        $oidcClient->expects($this->never())->method('buildEndSessionUrl');

        $listener = new OidcEndSessionListener($oidcClient, new HttpUtils(), '/');

        $event = new LogoutEvent(Request::create('/logout'), null);
        $listener->onLogout($event);

        $this->assertNull($event->getResponse());
    }
}
