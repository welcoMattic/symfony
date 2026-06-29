<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Symfony\Bundle\SecurityBundle\Routing\OidcLoginRouteLoader;
use Symfony\Component\Security\Http\Authenticator\Oidc\OidcConfidentialClient;
use Symfony\Component\Security\Http\Authenticator\Oidc\OidcDiscovery;
use Symfony\Component\Security\Http\Authenticator\OidcLoginAuthenticator;

return static function (ContainerConfigurator $container) {
    $container->services()
        ->set('security.authenticator.oidc_login', OidcLoginAuthenticator::class)
            ->abstract()
            ->args([
                service('security.http_utils'),
                abstract_arg('OIDC client'),
                abstract_arg('OIDC discovery'),
                abstract_arg('client ID'),
                abstract_arg('authentication success handler'),
                abstract_arg('authentication failure handler'),
                abstract_arg('options'),
            ])

        ->set('security.authenticator.oidc_login.discovery', OidcDiscovery::class)
            ->abstract()
            ->args([
                service('http_client'),
                service('cache.app'),
                abstract_arg('OpenID configuration URL'),
                abstract_arg('issuer'),
            ])

        ->set('security.authenticator.oidc_login.client', OidcConfidentialClient::class)
            ->abstract()
            ->args([
                service('http_client'),
                abstract_arg('OIDC discovery'),
                abstract_arg('client ID'),
                abstract_arg('client secret'),
            ])

        ->set('security.authenticator.oidc_login.route_loader', OidcLoginRouteLoader::class)
            ->args([
                '%security.oidc_login.callback_uris%',
                'security.oidc_login.callback_uris',
            ])
            ->tag('routing.route_loader')
    ;
};
