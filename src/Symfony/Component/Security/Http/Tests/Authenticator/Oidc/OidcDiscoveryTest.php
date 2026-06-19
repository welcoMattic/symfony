<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Security\Http\Tests\Authenticator\Oidc;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Http\Authenticator\Oidc\OidcDiscovery;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class OidcDiscoveryTest extends TestCase
{
    private const CONFIGURATION = [
        'issuer' => 'https://provider.example.com',
        'authorization_endpoint' => 'https://provider.example.com/authorize',
        'token_endpoint' => 'https://provider.example.com/token',
    ];

    public function testGetConfigurationFetchesAndDecodesTheDocument()
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('toArray')->willReturn(self::CONFIGURATION);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->once())
            ->method('request')
            ->with('GET', 'https://provider.example.com/.well-known/openid-configuration')
            ->willReturn($response);

        $discovery = new OidcDiscovery($httpClient, $this->cacheRunningCallback(), 'https://provider.example.com/.well-known/openid-configuration');

        $this->assertSame(self::CONFIGURATION, $discovery->getConfiguration());
    }

    public function testGetConfigurationServesFromCacheWithoutHttpCall()
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->never())->method('request');

        $cache = $this->createStub(CacheInterface::class);
        $cache->method('get')->willReturn(self::CONFIGURATION);

        $discovery = new OidcDiscovery($httpClient, $cache, 'https://provider.example.com/.well-known/openid-configuration');

        $this->assertSame(self::CONFIGURATION, $discovery->getConfiguration());
    }

    private function cacheRunningCallback(): CacheInterface
    {
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('get')->willReturnCallback(fn (string $key, callable $callback) => $callback($this->createStub(ItemInterface::class)));

        return $cache;
    }
}
