<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HttpClient\Exception;

use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Thrown when recording is required but disabled.
 *
 * @author Mathieu Santostefano <msantostefano@protonmail.com>
 */
class RecordingDisabledException extends \RuntimeException implements TransportExceptionInterface
{
    public function __construct(string $message = 'Recording is disabled.')
    {
        parent::__construct($message);
    }
}
