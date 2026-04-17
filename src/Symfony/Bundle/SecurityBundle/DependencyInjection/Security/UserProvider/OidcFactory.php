<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bundle\SecurityBundle\DependencyInjection\Security\UserProvider;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * OidcFactory creates services for the OIDC user provider.
 *
 * @author Mathieu Santostefano <msantostefano@proton.me>
 */
class OidcFactory implements UserProviderFactoryInterface
{
    public function create(ContainerBuilder $container, string $id, array $config): void
    {
        $container->setDefinition($id, new ChildDefinition('security.user.provider.oidc'));
    }

    public function getKey(): string
    {
        return 'oidc';
    }

    /**
     * @param ArrayNodeDefinition $node
     */
    public function addConfiguration(NodeDefinition $node): void
    {
        // Marker key ensures the config is non-empty so the SecurityExtension
        // recognizes the factory should be invoked. The key itself is unused.
        $node
            ->beforeNormalization()
                ->ifTrue(fn ($v) => null === $v || (\is_array($v) && [] === $v))
                ->then(fn () => ['enabled' => true])
            ->end()
            ->children()
                ->booleanNode('enabled')->defaultTrue()->info('Internal marker; the OIDC provider has no configuration options.')->end()
            ->end()
        ;
    }
}
