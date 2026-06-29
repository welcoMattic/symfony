<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bundle\SecurityBundle\Tests\DependencyInjection\Security\Factory;

use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\DependencyInjection\Security\Factory\OidcLoginFactory;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

class OidcLoginFactoryTest extends TestCase
{
    public function testBasicServiceConfiguration()
    {
        $container = new ContainerBuilder();

        $config = [
            'provider_uri' => 'https://provider.example.com',
            'client_id' => 'my-client-id',
            'client_secret' => 'my-client-secret',
            'check_path' => '/oidc/callback',
        ];

        $factory = new OidcLoginFactory();
        $finalizedConfig = $this->processConfig($config, $factory);
        $factory->createAuthenticator($container, 'main', $finalizedConfig, 'userprovider');

        $this->assertTrue($container->hasDefinition('security.authenticator.oidc_login'));
        $this->assertTrue($container->hasDefinition('security.authenticator.oidc_login.main'));
        $this->assertTrue($container->hasDefinition('security.authenticator.oidc_login.discovery.main'));
        $this->assertTrue($container->hasDefinition('security.authenticator.oidc_login.client.main'));

        // the discovery service and the client id are injected directly, not fetched through the OIDC client
        $authenticator = $container->getDefinition('security.authenticator.oidc_login.main');
        $this->assertEquals(new Reference('security.authenticator.oidc_login.discovery.main'), $authenticator->getArgument(2));
        $this->assertSame('my-client-id', $authenticator->getArgument(3));
    }

    public function testGetKey()
    {
        $factory = new OidcLoginFactory();

        $this->assertSame('oidc-login', $factory->getKey());
    }

    public function testGetPriority()
    {
        $factory = new OidcLoginFactory();

        $this->assertSame(-25, $factory->getPriority());
    }

    public function testRequiredOptions()
    {
        $factory = new OidcLoginFactory();

        $this->expectException(InvalidConfigurationException::class);

        $this->processConfig([
            'client_id' => 'my-client-id',
            'client_secret' => 'my-client-secret',
        ], $factory);
    }

    public function testCheckPathDefaultsToCallback()
    {
        $factory = new OidcLoginFactory();

        $finalizedConfig = $this->processConfig([
            'provider_uri' => 'https://provider.example.com',
            'client_id' => 'my-client-id',
            'client_secret' => 'my-client-secret',
        ], $factory);

        $this->assertSame('/oidc/callback', $finalizedConfig['check_path']);
    }

    public function testCallbackRouteIsRegistered()
    {
        $container = new ContainerBuilder();

        $config = [
            'provider_uri' => 'https://provider.example.com',
            'client_id' => 'my-client-id',
            'client_secret' => 'my-client-secret',
            'check_path' => '/oidc/callback',
        ];

        $factory = new OidcLoginFactory();
        $finalizedConfig = $this->processConfig($config, $factory);
        $factory->createAuthenticator($container, 'main', $finalizedConfig, 'userprovider');

        $this->assertTrue($container->hasDefinition('security.authenticator.oidc_login.route_loader'));
        $this->assertSame(['main' => '/oidc/callback'], $container->getParameter('security.oidc_login.callback_uris'));
    }

    public function testRejectsNonHttpsProviderUri()
    {
        $factory = new OidcLoginFactory();

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('must use HTTPS');

        $this->processConfig([
            'provider_uri' => 'http://provider.example.com',
            'client_id' => 'my-client-id',
            'client_secret' => 'my-client-secret',
        ], $factory);
    }

    public function testAllowsHttpProviderUriForLoopback()
    {
        $factory = new OidcLoginFactory();

        $finalizedConfig = $this->processConfig([
            'provider_uri' => 'http://localhost:8080',
            'client_id' => 'my-client-id',
            'client_secret' => 'my-client-secret',
        ], $factory);

        $this->assertSame('http://localhost:8080', $finalizedConfig['provider_uri']);
    }

    private function processConfig(array $config, OidcLoginFactory $factory): array
    {
        $nodeDefinition = new ArrayNodeDefinition('oidc-login');
        $factory->addConfiguration($nodeDefinition);

        $node = $nodeDefinition->getNode();
        $normalizedConfig = $node->normalize($config);

        return $node->finalize($normalizedConfig);
    }
}
