<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bundle\SecurityBundle\DependencyInjection\Security\Factory;

use Jose\Component\Core\Algorithm;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\LogicException;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Security\Http\Oidc\OidcDiscovery;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * OidcLoginFactory creates services for OpenID Connect Authorization Code Flow authentication.
 *
 * @author Mathieu Santostefano <msantostefano@proton.me>
 *
 * @internal
 */
class OidcLoginFactory extends AbstractFactory
{
    public const PRIORITY = -25;

    public function __construct()
    {
        $this->addOption('direct_redirect', false);
        $this->addOption('user_identifier_claim', 'sub');
    }

    /**
     * @psalm-suppress ParamNameMismatch
     */
    public function addConfiguration(NodeDefinition $node): void
    {
        parent::addConfiguration($node);

        \assert($node instanceof ArrayNodeDefinition);

        $node->children()
            ->scalarNode('provider_uri')
                ->isRequired()
                ->validate()
                    ->ifTrue(static function ($v): bool {
                        // An environment variable is an empty string at this point, so it cannot
                        // be checked here; OidcDiscovery checks the resolved value at runtime.
                        // Declaring the node ->cannotBeEmpty() is what would make the Config
                        // component reject environment variables outright, next to a validator.
                        if ('' === (string) $v) {
                            return false;
                        }

                        return !OidcDiscovery::isSecureUrl((string) $v);
                    })
                    ->thenInvalid('The OIDC "provider_uri" must use HTTPS (got %s): the authorization code, the PKCE verifier and the tokens it is exchanged for are only confidential over TLS. Use HTTPS, or a loopback host (localhost, 127.0.0.1, ::1) or a name reserved for testing (*.localhost, *.test) for local development.')
                ->end()
                ->info('The OIDC Issuer URL (e.g. "https://accounts.example.com"). Used for .well-known/openid-configuration discovery.')
            ->end()
            ->scalarNode('client_id')
                ->isRequired()
                ->cannotBeEmpty()
                ->info('The OIDC client identifier.')
            ->end()
            ->scalarNode('client_secret')
                ->defaultNull()
                ->cannotBeEmpty()
                ->info('The OIDC client secret. Required by every "token_endpoint_auth_method" but "none", which declares a public client.')
            ->end()
            ->arrayNode('scope')
                ->beforeNormalization()->castToArray()->end()
                ->scalarPrototype()->end()
                ->defaultValue(['openid'])
                ->info('The scopes of the authorization request, as a list or as a space-separated string. "openid" is always requested, as OIDC requires it; add e.g. "profile" or "email" to get the matching claims from the UserInfo endpoint, and map them onto your own user with a user provider.')
            ->end()
            ->scalarNode('check_path')
                ->cannotBeEmpty()
                ->defaultValue('/oidc/callback')
                ->info('The firewall path where the OIDC provider redirects after authentication. Must match a redirect URI registered with the provider. A route is declared for this path by the "security.authenticator.oidc_login.route_loader" service, which the application must import (see the OIDC login documentation), as it does for the logout routes. A route name is accepted too, in which case no route is declared for it.')
            ->end()
            ->integerNode('discovery_cache_ttl')
                ->defaultValue(3600)
                ->min(0)
                ->info('TTL in seconds for caching the OIDC discovery configuration.')
            ->end()
            ->integerNode('allowed_time_drift')
                ->defaultValue(0)
                ->min(0)
                ->info('Allowed clock skew in seconds when validating ID token time claims.')
            ->end()
            ->booleanNode('direct_redirect')
                ->defaultFalse()
                ->info('When true (typical for a "Log in with..." button), the entry point redirects straight to the OIDC provider to start the flow. When false, it redirects to the firewall login_path instead.')
            ->end()
            ->enumNode('token_endpoint_auth_method')
                ->values(['client_secret_post', 'client_secret_basic', 'none'])
                ->defaultValue('client_secret_post')
                ->info('Authentication method for the token endpoint. "none" declares a public client (a SPA, a mobile or a native application), which holds no secret and relies on PKCE to protect the code exchange.')
            ->end()

            ->arrayNode('pkce')
                ->addDefaultsIfNotSet()
                ->children()
                    ->booleanNode('enabled')->defaultTrue()->info('Enable PKCE (Proof Key for Code Exchange).')->end()
                    ->scalarNode('method')->defaultValue('S256')->info('PKCE code challenge method. Must match a service tagged "security.oidc.pkce_method" (builtin: "S256", "plain").')->end()
                ->end()
            ->end()
            ->enumNode('prompt')
                ->values(['none', 'login', 'consent', 'select_account'])
                ->info('OIDC "prompt" parameter. For multi-value combinations, use "authorization_params.prompt" instead.')
            ->end()
            ->integerNode('max_age')
                ->min(0)
                ->info('Max seconds since last end-user authentication. Triggers re-authentication when exceeded.')
            ->end()
            ->enumNode('user_data_source')
                ->values(['userinfo', 'id_token'])
                ->defaultValue('userinfo')
                ->info('Source of user claims: "userinfo" fetches from the UserInfo endpoint, "id_token" decodes claims from the ID token.')
            ->end()
            ->booleanNode('enable_end_session')
                ->defaultFalse()
                ->info('Enable RP-Initiated Logout via the OIDC end_session_endpoint.')
            ->end()
            ->scalarNode('post_logout_redirect_path')
                ->defaultValue('/')
                ->info('Path or route to redirect to after OIDC logout.')
            ->end()
            ->arrayNode('authorization_params')
                ->useAttributeAsKey('name')
                ->scalarPrototype()->end()
                ->defaultValue([])
                ->info('Additional parameters to include in the authorization request (e.g. prompt, max_age, display, ui_locales, acr_values, login_hint).')
            ->end()
        ;

        // the client type is what "token_endpoint_auth_method" really selects, so the
        // options it makes mandatory or meaningless can only be checked here, once the
        // whole authenticator configuration is known
        $node
            ->validate()
                ->ifTrue(static fn ($v): bool => 'none' !== $v['token_endpoint_auth_method'] && null === $v['client_secret'])
                ->thenInvalid('The OIDC "client_secret" is required by the "token_endpoint_auth_method" in use, which defaults to "client_secret_post". Set a secret, or set "token_endpoint_auth_method" to "none" to declare a public client, which authenticates with its "client_id" and PKCE only.')
            ->end()
            ->validate()
                ->ifTrue(static fn ($v): bool => 'none' === $v['token_endpoint_auth_method'] && null !== $v['client_secret'])
                ->thenInvalid('The OIDC "client_secret" must not be set when "token_endpoint_auth_method" is "none", as a public client never sends it. Remove the secret, or authenticate with it by using "client_secret_post" or "client_secret_basic".')
            ->end()
            ->validate()
                ->ifTrue(static fn ($v): bool => 'none' === $v['token_endpoint_auth_method'] && !$v['pkce']['enabled'])
                ->thenInvalid('The OIDC "pkce.enabled" option cannot be false when "token_endpoint_auth_method" is "none": a public client sends no secret, so PKCE is the only thing binding the authorization code to it.')
            ->end()
        ;
    }

    public function getKey(): string
    {
        return 'oidc-login';
    }

    public function getPriority(): int
    {
        return self::PRIORITY;
    }

    public function createAuthenticator(ContainerBuilder $container, string $firewallName, array $config, string $userProviderId): string
    {
        if (!ContainerBuilder::willBeAvailable('symfony/http-client', HttpClientInterface::class, ['symfony/security-bundle'])) {
            throw new LogicException('You cannot use the "oidc-login" authenticator since the HttpClient component is not installed. Try running "composer require symfony/http-client".');
        }

        if (!ContainerBuilder::willBeAvailable('web-token/jwt-library', Algorithm::class, ['symfony/security-bundle'])) {
            throw new LogicException('You cannot use the "oidc-login" authenticator since "web-token/jwt-library" is not installed. Try running "composer require web-token/jwt-library".');
        }

        if (!$container->hasDefinition('security.authenticator.oidc_login')) {
            $loader = new PhpFileLoader($container, new FileLocator(\dirname(__DIR__).'/../../Resources/config'));
            $loader->load('security_authenticator_oidc_login.php');
        }

        $discoveryId = 'security.authenticator.oidc_login.discovery.'.$firewallName;
        $container
            ->setDefinition($discoveryId, new ChildDefinition('security.authenticator.oidc_login.discovery'))
            // the discovery URL is built from the issuer by OidcDiscovery, which is the only
            // place a "provider_uri" coming from an environment variable can be normalized
            ->replaceArgument(3, $config['provider_uri'])
            ->replaceArgument(4, $config['discovery_cache_ttl'])
            ->addTag('kernel.reset', ['method' => 'reset'])
        ;

        $idTokenId = 'security.authenticator.oidc_login.id_token.'.$firewallName;
        $container
            ->setDefinition($idTokenId, (new ChildDefinition('security.authenticator.oidc_login.id_token'))
                ->replaceArgument(1, $config['allowed_time_drift'])
            )
        ;

        $oidcClientId = 'security.authenticator.oidc_login.client.'.$firewallName;
        if ('none' === ($config['token_endpoint_auth_method'] ?? 'client_secret_post')) {
            // a public client holds no secret, so it only tells the token endpoint which
            // client_id the authorization code was issued to, and proves it with PKCE
            $container
                ->setDefinition($oidcClientId, new ChildDefinition('security.authenticator.oidc_login.public_client'))
                ->replaceArgument(1, new Reference($discoveryId))
                ->replaceArgument(2, $config['client_id'])
            ;
        } else {
            $container
                ->setDefinition($oidcClientId, new ChildDefinition('security.authenticator.oidc_login.client'))
                ->replaceArgument(1, new Reference($discoveryId))
                ->replaceArgument(2, $config['client_id'])
                ->replaceArgument(3, $config['client_secret'])
                ->replaceArgument(4, $config['token_endpoint_auth_method'] ?? 'client_secret_post')
            ;
        }

        $authenticatorId = 'security.authenticator.oidc_login.'.$firewallName;
        $options = array_intersect_key($config, $this->options);
        $options['user_data_source'] = $config['user_data_source'];
        $options['firewall_name'] = $firewallName;
        $options['scope'] = $config['scope'];
        $options['user_data_source'] = $config['user_data_source'];
        $options['pkce_enabled'] = $config['pkce']['enabled'];
        $options['pkce_method'] = $config['pkce']['method'];
        if (isset($config['max_age'])) {
            $options['max_age'] = $config['max_age'];
        }

        $authorizationParams = [];
        if (isset($config['prompt'])) {
            $authorizationParams['prompt'] = $config['prompt'];
        }
        if (isset($config['max_age'])) {
            $authorizationParams['max_age'] = (string) $config['max_age'];
        }
        $authorizationParams = array_merge($authorizationParams, $config['authorization_params'] ?? []);

        $container
            ->setDefinition($authenticatorId, new ChildDefinition('security.authenticator.oidc_login'))
            ->replaceArgument(1, new Reference($userProviderId))
            ->replaceArgument(2, new Reference($oidcClientId))
            ->replaceArgument(3, new Reference($discoveryId))
            ->replaceArgument(4, new Reference($idTokenId))
            ->replaceArgument(5, $config['client_id'])
            ->replaceArgument(6, new Reference($this->createAuthenticationSuccessHandler($container, $firewallName, $config)))
            ->replaceArgument(7, new Reference($this->createAuthenticationFailureHandler($container, $firewallName, $config)))
            ->replaceArgument(9, $options)
            ->replaceArgument(10, $authorizationParams)
        ;

        if ($config['enable_end_session']) {
            $endSessionListenerId = 'security.authenticator.oidc_login.end_session_listener.'.$firewallName;
            $container
                ->setDefinition($endSessionListenerId, new ChildDefinition('security.authenticator.oidc_login.end_session_listener'))
                ->replaceArgument(0, new Reference($discoveryId))
                ->replaceArgument(2, $config['post_logout_redirect_path'])
                ->addTag('kernel.event_subscriber', ['dispatcher' => 'security.event_dispatcher.'.$firewallName])
            ;
        }

        $callbackUris = $container->hasParameter('security.oidc_login.callback_uris') ? (array) $container->getParameter('security.oidc_login.callback_uris') : [];
        // a "check_path" holding a route name instead of a path gets no route declared
        // for it; the parameter is always set, as the route loader is wired on it
        if ('/' === $config['check_path'][0]) {
            $callbackUris[$firewallName] = $config['check_path'];
        }
        $container->setParameter('security.oidc_login.callback_uris', $callbackUris);

        return $authenticatorId;
    }
}
