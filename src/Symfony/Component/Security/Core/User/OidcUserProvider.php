<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Security\Core\User;

use Symfony\Component\Security\Core\Exception\UnsupportedUserException;

/**
 * User provider for OIDC users that are created by the OIDC login authenticator.
 *
 * This provider supports refreshing OidcUser instances from the session.
 * Since OIDC users are self-contained (all claims come from the provider),
 * the user is returned as-is without any external lookup.
 *
 * @author Mathieu Santostefano <msantostefano@proton.me>
 *
 * @implements UserProviderInterface<OidcUser>
 */
final class OidcUserProvider implements UserProviderInterface
{
    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        throw new \LogicException('OIDC users are loaded by the OIDC authenticator, not by this provider.');
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof OidcUser) {
            throw new UnsupportedUserException(\sprintf('Instances of "%s" are not supported.', $user::class));
        }

        return $user;
    }

    public function supportsClass(string $class): bool
    {
        return OidcUser::class === $class;
    }
}
