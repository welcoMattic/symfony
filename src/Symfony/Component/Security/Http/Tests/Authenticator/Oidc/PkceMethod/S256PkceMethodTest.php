<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Security\Http\Tests\Authenticator\Oidc\PkceMethod;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Http\Authenticator\Oidc\PkceMethod\S256PkceMethod;

class S256PkceMethodTest extends TestCase
{
    public function testName()
    {
        $this->assertSame('S256', S256PkceMethod::getName());
    }

    /**
     * Test vector from RFC 7636 Appendix B.
     */
    public function testCreateChallengeMatchesRfc7636Vector()
    {
        $verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
        $expectedChallenge = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

        $this->assertSame($expectedChallenge, (new S256PkceMethod())->createChallenge($verifier));
    }

    public function testChallengeIsBase64UrlWithoutPadding()
    {
        $challenge = (new S256PkceMethod())->createChallenge('any-verifier');

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $challenge);
        $this->assertStringNotContainsString('=', $challenge);
    }
}
