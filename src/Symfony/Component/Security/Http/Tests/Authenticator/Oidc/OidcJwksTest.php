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
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\Security\Http\Authenticator\Oidc\OidcJwks;
use Symfony\Contracts\Cache\ItemInterface;

class OidcJwksTest extends TestCase
{
    public function testFromResponseKeepsOnlySignatureKeysAndReadsMaxAge()
    {
        $response = (new MockHttpClient(new JsonMockResponse(
            ['keys' => [
                ['kid' => 'sig-key', 'use' => 'sig'],
                ['kid' => 'enc-key', 'use' => 'enc'],
                ['kid' => 'no-use'],
            ]],
            ['response_headers' => ['cache-control' => 'public, max-age=600']],
        )))->request('GET', 'https://provider.example.com/jwks');

        [$keys, $ttl] = OidcJwks::fromResponse($response);

        $this->assertSame([['kid' => 'sig-key', 'use' => 'sig']], $keys);
        $this->assertSame(600, $ttl);
    }

    public function testFromResponseReturnsNullTtlWithoutCacheHeaders()
    {
        $response = (new MockHttpClient(new JsonMockResponse(['keys' => []])))
            ->request('GET', 'https://provider.example.com/jwks');

        [$keys, $ttl] = OidcJwks::fromResponse($response);

        $this->assertSame([], $keys);
        $this->assertNull($ttl);
    }

    public function testFetchKeysAppliesProviderTtlToCacheItem()
    {
        $httpClient = new MockHttpClient(new JsonMockResponse(
            ['keys' => [['kid' => 'sig-key', 'use' => 'sig']]],
            ['response_headers' => ['cache-control' => 'max-age=120']],
        ));

        $item = $this->createMock(ItemInterface::class);
        $item->expects($this->once())->method('expiresAfter')->with(120);

        $keys = OidcJwks::fetchKeys($httpClient, 'https://provider.example.com/jwks', $item);

        $this->assertSame([['kid' => 'sig-key', 'use' => 'sig']], $keys);
    }

    public function testFetchKeysFallsBackToDefaultTtl()
    {
        $httpClient = new MockHttpClient(new JsonMockResponse(['keys' => []]));

        $item = $this->createMock(ItemInterface::class);
        $item->expects($this->once())->method('expiresAfter')->with(3600);

        OidcJwks::fetchKeys($httpClient, 'https://provider.example.com/jwks', $item);
    }

    public function testFetchKeysIsUsableAsACacheCallback()
    {
        $httpClient = new MockHttpClient(new JsonMockResponse(['keys' => [['kid' => 'sig-key', 'use' => 'sig']]]));
        $cache = new ArrayAdapter();

        $keys = $cache->get('jwks', fn (ItemInterface $item) => OidcJwks::fetchKeys($httpClient, 'https://provider.example.com/jwks', $item));

        $this->assertSame([['kid' => 'sig-key', 'use' => 'sig']], $keys);
    }
}
