<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HttpClient\Recording;

/**
 * Defines the behavior of the RecordingHttpClient.
 *
 * @author Mathieu Santostefano <msantostefano@protonmail.com>
 */
enum RecordingMode: string
{
    /**
     * Always record new responses, replacing any existing recordings.
     */
    case RECORD = 'record';

    /**
     * Only replay recorded responses, throw exception on unknown requests.
     */
    case PLAYBACK = 'playback';

    /**
     * Record the first request, then replay on subsequent requests.
     */
    case ONCE = 'once';

    /**
     * Replay recorded requests, record missing requests.
     */
    case RECORD_IF_MISSING = 'record_if_missing';

    /**
     * Bypass recording entirely, pass through to underlying client.
     */
    case PASSTHROUGH = 'passthrough';
}
