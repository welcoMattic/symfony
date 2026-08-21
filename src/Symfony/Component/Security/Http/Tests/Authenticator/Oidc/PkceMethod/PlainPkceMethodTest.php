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
use Symfony\Component\Security\Http\Authenticator\Oidc\PkceMethod\PlainPkceMethod;

class PlainPkceMethodTest extends TestCase
{
    public function testName()
    {
        $this->assertSame('plain', PlainPkceMethod::getName());
    }

    public function testCreateChallengeIsPassthrough()
    {
        $verifier = 'my-verifier-value';

        $this->assertSame($verifier, (new PlainPkceMethod())->createChallenge($verifier));
    }
}
