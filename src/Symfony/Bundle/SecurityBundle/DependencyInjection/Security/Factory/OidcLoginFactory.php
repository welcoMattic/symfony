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
    public const PRIORITY = -25;

    public function __construct()
    {
        $this->addOption('direct_redirect', false);
        $this->addOption('claim', 'sub');
        $this->addOption('enable_userinfo', true);
    }

    public function addConfiguration(NodeDefinition $node): void
    {
        parent::addConfiguration($node);

        $builder = $node->children();

        $builder
            ->arrayNode('scopes')
                ->scalarPrototype()->end()
                ->defaultValue(['openid'])
                ->info('OAuth2 scopes to request during authorization.')
            ->end()
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
            ->arrayNode('pkce')
                ->addDefaultsIfNotSet()
                ->children()
                    ->booleanNode('enabled')->defaultTrue()->info('Enable PKCE (Proof Key for Code Exchange).')->end()
                    ->enumNode('method')->values(['S256', 'plain'])->defaultValue('S256')->info('PKCE code challenge method.')->end()
                ->end()
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

        // Discovery service
        $discoveryId = 'security.authenticator.oidc_login.discovery.'.$firewallName;
        $container
            ->setDefinition($discoveryId, new ChildDefinition('security.authenticator.oidc_login.discovery'))
            ->replaceArgument(2, $providerUri.'/.well-known/openid-configuration')
            ->replaceArgument(3, $config['discovery_cache_ttl'])
        ;

        // Client credentials
        $credentialsId = 'security.authenticator.oidc_login.credentials.'.$firewallName;
        $container
            ->setDefinition($credentialsId, new ChildDefinition('security.authenticator.oidc_login.credentials'))
            ->replaceArgument(0, $config['client_id'])
            ->replaceArgument(1, $config['client_secret'])
            ->replaceArgument(2, $config['token_endpoint_auth_method'] ?? 'client_secret_post')
        ;

        // OIDC Client
        $oidcClientId = 'security.authenticator.oidc_login.client.'.$firewallName;
        $container
            ->setDefinition($oidcClientId, new ChildDefinition('security.authenticator.oidc_login.client'))
            ->replaceArgument(1, new Reference($discoveryId))
            ->replaceArgument(3, $firewallName)
            ->replaceArgument(4, new Reference($credentialsId))
            ->replaceArgument(5, $config['check_path'])
            ->replaceArgument(6, $config['scopes'] ?? ['openid'])
            ->replaceArgument(7, $config['pkce']['enabled'] ?? true)
            ->replaceArgument(8, $config['pkce']['method'] ?? 'S256')
        ;

        // Authenticator
        $authenticatorId = 'security.authenticator.oidc_login.'.$firewallName;
        $options = array_intersect_key($config, $this->options);
        $container
            ->setDefinition($authenticatorId, new ChildDefinition('security.authenticator.oidc_login'))
            ->replaceArgument(1, new Reference($oidcClientId))
            ->replaceArgument(2, new Reference($this->createAuthenticationSuccessHandler($container, $firewallName, $config)))
            ->replaceArgument(3, new Reference($this->createAuthenticationFailureHandler($container, $firewallName, $config)))
            ->replaceArgument(4, $options)
            ->replaceArgument(5, $config['authorization_params'] ?? [])
        ;

        // RP-Initiated Logout listener
        if ($config['enable_end_session']) {
            $endSessionListenerId = 'security.authenticator.oidc_login.end_session_listener.'.$firewallName;
            $container
                ->setDefinition($endSessionListenerId, new ChildDefinition('security.authenticator.oidc_login.end_session_listener'))
                ->replaceArgument(0, new Reference($oidcClientId))
                ->replaceArgument(2, $config['post_logout_redirect_path'])
                ->addTag('kernel.event_subscriber', ['dispatcher' => 'security.event_dispatcher.'.$firewallName])
            ;
        }

        return $authenticatorId;
    }
}
