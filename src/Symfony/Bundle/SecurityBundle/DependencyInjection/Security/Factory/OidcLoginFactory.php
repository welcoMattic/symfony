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

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
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
                ->cannotBeEmpty()
                ->validate()
                    ->ifTrue(static function ($v): bool {
                        if ('https' === parse_url((string) $v, \PHP_URL_SCHEME)) {
                            return false;
                        }

                        return !\in_array(parse_url((string) $v, \PHP_URL_HOST), ['localhost', '127.0.0.1', '::1'], true);
                    })
                    ->thenInvalid('The OIDC "provider_uri" must use HTTPS (got %s): the ID token signature is not verified, so transport security is mandatory. Use HTTPS, or a loopback host (localhost, 127.0.0.1) for local development.')
                ->end()
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
            ->scalarNode('check_path')
                ->cannotBeEmpty()
                ->defaultValue('/oidc/callback')
                ->info('The firewall path where the OIDC provider redirects after authentication. Must match a redirect URI registered with the provider. A route is registered automatically for this path.')
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
            ->replaceArgument(3, $providerUri)
        ;

        $oidcClientId = 'security.authenticator.oidc_login.client.'.$firewallName;
        $container
            ->setDefinition($oidcClientId, new ChildDefinition('security.authenticator.oidc_login.client'))
            ->replaceArgument(1, new Reference($discoveryId))
            ->replaceArgument(2, $config['client_id'])
            ->replaceArgument(3, $config['client_secret'])
        ;

        $authenticatorId = 'security.authenticator.oidc_login.'.$firewallName;
        $options = array_intersect_key($config, $this->options);
        $options['firewall_name'] = $firewallName;

        $container
            ->setDefinition($authenticatorId, new ChildDefinition('security.authenticator.oidc_login'))
            ->replaceArgument(1, new Reference($oidcClientId))
            ->replaceArgument(2, new Reference($discoveryId))
            ->replaceArgument(3, $config['client_id'])
            ->replaceArgument(4, new Reference($this->createAuthenticationSuccessHandler($container, $firewallName, $config)))
            ->replaceArgument(5, new Reference($this->createAuthenticationFailureHandler($container, $firewallName, $config)))
            ->replaceArgument(6, $options)
        ;

        $callbackUris = $container->hasParameter('security.oidc_login.callback_uris') ? (array) $container->getParameter('security.oidc_login.callback_uris') : [];
        $callbackUris[$firewallName] = $config['check_path'];
        $container->setParameter('security.oidc_login.callback_uris', $callbackUris);

        return $authenticatorId;
    }
}
