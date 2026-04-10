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
use Symfony\Component\Security\Http\Authenticator\Oidc\OidcDiscovery;
use Symfony\Component\Security\Http\Event\LogoutEvent;
use Symfony\Component\Security\Http\EventListener\OidcEndSessionListener;
use Symfony\Component\Security\Http\HttpUtils;

class OidcEndSessionListenerTest extends TestCase
{
    public function testOnLogoutRedirectsToEndSessionEndpoint()
    {
        $discovery = $this->createMock(OidcDiscovery::class);
        $discovery->method('getConfiguration')->willReturn([
            'end_session_endpoint' => 'https://provider.example.com/logout',
        ]);

        $listener = new OidcEndSessionListener($discovery, new HttpUtils(), '/');

        $token = $this->createMock(TokenInterface::class);
        $token->method('hasAttribute')->with('oidc_id_token')->willReturn(true);
        $token->method('getAttribute')->with('oidc_id_token')->willReturn('my-id-token');

        $event = new LogoutEvent(Request::create('/logout'), $token);
        $listener->onLogout($event);

        $response = $event->getResponse();
        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertStringStartsWith('https://provider.example.com/logout?', $response->getTargetUrl());
        $this->assertStringContainsString('id_token_hint=my-id-token', $response->getTargetUrl());
    }

    public function testOnLogoutDoesNothingWithoutOidcIdToken()
    {
        $discovery = $this->createMock(OidcDiscovery::class);
        $discovery->expects($this->never())->method('getConfiguration');

        $listener = new OidcEndSessionListener($discovery, new HttpUtils(), '/');

        $token = $this->createMock(TokenInterface::class);
        $token->method('hasAttribute')->with('oidc_id_token')->willReturn(false);

        $event = new LogoutEvent(Request::create('/logout'), $token);
        $listener->onLogout($event);

        $this->assertNull($event->getResponse());
    }

    public function testOnLogoutDoesNothingWithoutToken()
    {
        $discovery = $this->createMock(OidcDiscovery::class);
        $discovery->expects($this->never())->method('getConfiguration');

        $listener = new OidcEndSessionListener($discovery, new HttpUtils(), '/');

        $event = new LogoutEvent(Request::create('/logout'), null);
        $listener->onLogout($event);

        $this->assertNull($event->getResponse());
    }
}
