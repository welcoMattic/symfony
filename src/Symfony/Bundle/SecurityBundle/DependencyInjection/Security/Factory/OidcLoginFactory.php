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

use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;

/**
 * OidcLoginFactory creates services for OpenID Connect Authorization Code Flow authentication.
 *
 * @author Mathieu Santostefano <msantostefano@proton.me>
 *
 * @internal
 */
class OidcLoginFactory extends AbstractFactory
{
    public const int PRIORITY = -25;

    public function __construct()
    {
        $this->addOption('direct_redirect', false);
        $this->addOption('user_identifier_claim', 'sub');
    }

    public function addConfiguration(NodeDefinition $node): void
    {
        parent::addConfiguration($node);

        $builder = $node->children();

        $builder
            ->scalarNode('provider_uri')
                ->isRequired()
                ->cannotBeEmpty()
                ->info('The OIDC Issuer URL (e.g. "https://accounts.example.com"). Used for .well-known/openid-configuration discovery.')
            ->end()
            ->scalarNode('client_id')
                ->isRequired()
                ->cannotBeEmpty()
                ->info('The OIDC client identifier.')
            ->end()
            ->scalarNode('client_secret')
                ->isRequired()
                ->cannotBeEmpty()
                ->info('The OIDC client secret.')
            ->end()
            ->enumNode('token_endpoint_auth_method')
                ->values(['client_secret_post', 'client_secret_basic'])
                ->defaultValue('client_secret_post')
                ->info('Authentication method for the token endpoint.')
            ->end()
            ->arrayNode('scopes')
                ->scalarPrototype()->end()
                ->defaultValue(['openid'])
                ->info('OAuth2 scopes to request during authorization.')
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
            ->integerNode('discovery_cache_ttl')
                ->defaultValue(3600)
                ->info('TTL in seconds for caching the OIDC discovery configuration.')
            ->end()
            ->arrayNode('authorization_params')
                ->useAttributeAsKey('name')
                ->scalarPrototype()->end()
                ->defaultValue([])
                ->info('Additional parameters to include in the authorization request (e.g. prompt, max_age, display, ui_locales, acr_values, login_hint).')
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
        if (!$container->hasDefinition('security.authenticator.oidc_login')) {
            $loader = new PhpFileLoader($container, new FileLocator(\dirname(__DIR__).'/../../Resources/config'));
            $loader->load('security_authenticator_oidc_login.php');
        }

        $providerUri = rtrim($config['provider_uri'], '/');

        $discoveryId = 'security.authenticator.oidc_login.discovery.'.$firewallName;
        $container
            ->setDefinition($discoveryId, new ChildDefinition('security.authenticator.oidc_login.discovery'))
            ->replaceArgument(2, $providerUri.'/.well-known/openid-configuration')
            ->replaceArgument(3, $config['discovery_cache_ttl'])
        ;

        $oidcClientId = 'security.authenticator.oidc_login.client.'.$firewallName;
        $container
            ->setDefinition($oidcClientId, new ChildDefinition('security.authenticator.oidc_login.client'))
            ->replaceArgument(1, new Reference($discoveryId))
            ->replaceArgument(2, $config['client_id'])
            ->replaceArgument(3, $config['client_secret'])
            ->replaceArgument(4, $config['token_endpoint_auth_method'] ?? 'client_secret_post')
        ;

        $authenticatorId = 'security.authenticator.oidc_login.'.$firewallName;
        $options = array_intersect_key($config, $this->options);
        $options['user_data_source'] = $config['user_data_source'];
        $options['firewall_name'] = $firewallName;
        $options['scopes'] = $config['scopes'] ?? ['openid'];
        $options['pkce_enabled'] = $config['pkce']['enabled'] ?? true;
        $options['pkce_method'] = $config['pkce']['method'] ?? 'S256';

        // First-class params (prompt, max_age) are merged under user-provided
        // authorization_params so an explicit authorization_params entry still wins.
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
            ->replaceArgument(1, new Reference($oidcClientId))
            ->replaceArgument(2, new Reference($this->createAuthenticationSuccessHandler($container, $firewallName, $config)))
            ->replaceArgument(3, new Reference($this->createAuthenticationFailureHandler($container, $firewallName, $config)))
            ->replaceArgument(5, $options)
            ->replaceArgument(6, $authorizationParams)
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

        return $authenticatorId;
    }
}
