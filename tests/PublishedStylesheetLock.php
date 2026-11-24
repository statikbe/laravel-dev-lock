<?php

declare(strict_types=1);

namespace Statikbe\DevLock\Tests;

use RuntimeException;

/**
 * Serialises the tests that write or read the published fallback stylesheet across parallel
 * test processes.
 *
 * That copy lives at resources/css/vendor/dev-lock/dev-lock.css inside Testbench's skeleton,
 * which every `pest --parallel` process shares, and Laravel derives the resources path from the
 * base path with no way to give each process its own. Without this lock one process reads, or
 * deletes, another's copy mid-test, which CI hit intermittently on random test orders.
 */
final class PublishedStylesheetLock
{
    /** @var resource|null */
    private static $handle = null;

    public static function acquire(): void
    {
        // Keyed on this checkout, so two clones of the package never wait on each other.
        $path = sys_get_temp_dir().'/dev-lock-published-stylesheet-'.md5(__DIR__).'.lock';
        $handle = fopen($path, 'c');

        if ($handle === false || ! flock($handle, LOCK_EX)) {
            throw new RuntimeException("Could not lock {$path}.");
        }

        self::$handle = $handle;
    }

    public static function release(): void
    {
        if (self::$handle === null) {
            return;
        }

        flock(self::$handle, LOCK_UN);
        fclose(self::$handle);
        self::$handle = null;
    }
}
