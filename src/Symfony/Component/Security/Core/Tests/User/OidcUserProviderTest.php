<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Security\Core\Tests\User;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\OidcUser;
use Symfony\Component\Security\Core\User\OidcUserProvider;

class OidcUserProviderTest extends TestCase
{
    public function testLoadUserByIdentifierIsNotSupported()
    {
        $provider = new OidcUserProvider();

        $this->expectException(\LogicException::class);

        $provider->loadUserByIdentifier('user-42');
    }

    public function testRefreshUserReturnsSameUser()
    {
        $provider = new OidcUserProvider();
        $user = new OidcUser(userIdentifier: 'user-42', sub: 'user-42');

        $this->assertSame($user, $provider->refreshUser($user));
    }

    public function testRefreshUnsupportedUser()
    {
        $provider = new OidcUserProvider();

        $this->expectException(UnsupportedUserException::class);

        $provider->refreshUser(new InMemoryUser('john', 'pass'));
    }

    public function testSupportsClass()
    {
        $provider = new OidcUserProvider();

        $this->assertTrue($provider->supportsClass(OidcUser::class));
        $this->assertFalse($provider->supportsClass(InMemoryUser::class));
    }
}
