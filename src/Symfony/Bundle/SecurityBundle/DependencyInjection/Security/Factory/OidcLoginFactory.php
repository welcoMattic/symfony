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
use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\LogicException;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;
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
            ->enumNode('response_type')
                ->values(['code', 'id_token', 'id_token token', 'code id_token', 'code token', 'code id_token token'])
                ->defaultValue('code')
                ->info('OIDC response_type. Non-"code" (implicit/hybrid) values require "response_mode: form_post".')
            ->end()
            ->enumNode('response_mode')
                ->values(['query', 'form_post'])
                ->info('How the provider returns the authorization response. Must be "form_post" for non-"code" response types (fragment responses are unreadable server-side).')
            ->end()
            ->booleanNode('verify_id_token_signature')
                ->defaultTrue()
                ->info('Verify the ID token JWS signature against the provider JWKS. Always enforced for non-"code" response types.')
            ->end()
            ->arrayNode('signature_algorithms')
                ->scalarPrototype()->end()
                ->defaultValue(['RS256'])
                ->info('Algorithms accepted to verify the ID token signature (e.g. RS256, ES256, PS256).')
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
        $options['response_type'] = $config['response_type'] ?? 'code';
        if (isset($config['response_mode'])) {
            $options['response_mode'] = $config['response_mode'];
        }
        if (isset($config['max_age'])) {
            // Passed to the authenticator too, so it can validate the ID token
            // "auth_time" claim against max_age on the callback (OIDC Core §3.1.3.7.12).
            $options['max_age'] = $config['max_age'];
        }

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

        $authenticatorDefinition = $container
            ->setDefinition($authenticatorId, new ChildDefinition('security.authenticator.oidc_login'))
            ->replaceArgument(1, new Reference($oidcClientId))
            ->replaceArgument(2, new Reference($this->createAuthenticationSuccessHandler($container, $firewallName, $config)))
            ->replaceArgument(3, new Reference($this->createAuthenticationFailureHandler($container, $firewallName, $config)))
            ->replaceArgument(5, $options)
            ->replaceArgument(6, $authorizationParams)
        ;

        // Signature verification is mandatory when the ID token is delivered through
        // the user agent (non-"code" response types), and recommended otherwise.
        $verifySignature = $config['verify_id_token_signature'] || 'code' !== ($config['response_type'] ?? 'code');
        if ($verifySignature) {
            if (!ContainerBuilder::willBeAvailable('web-token/jwt-library', Algorithm::class, ['symfony/security-bundle'])) {
                throw new LogicException('You cannot verify OIDC ID token signatures since the "web-token/jwt-library" package is not installed. Try running "composer require web-token/jwt-library", or set "verify_id_token_signature: false" (allowed for the "code" response type only).');
            }
            if (!ContainerBuilder::willBeAvailable('symfony/http-client', HttpClientInterface::class, ['symfony/security-bundle'])) {
                throw new LogicException('You cannot verify OIDC ID token signatures without the HttpClient component. Try running "composer require symfony/http-client".');
            }

            $verifierId = 'security.authenticator.oidc_login.signature_verifier.'.$firewallName;
            $container
                ->setDefinition($verifierId, new ChildDefinition('security.authenticator.oidc_login.signature_verifier'))
                ->replaceArgument(0, $config['signature_algorithms'])
                ->replaceArgument(1, new Reference($discoveryId))
                ->replaceArgument(4, $config['discovery_cache_ttl'])
            ;

            $authenticatorDefinition->replaceArgument(7, new Reference($verifierId));
        }

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
