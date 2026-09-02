<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Case;

use Symfony\Component\ErrorHandler\ErrorHandler;

/**
 * Takes a kernel's error handlers back off the stack it put them on.
 *
 * Booting a Symfony kernel installs `ErrorHandler` — `FrameworkBundle::boot()` registers it
 * whenever symfony/runtime is not there to have done it already — and shutting the kernel down
 * does not take it off again. PHPUnit notices the handler that outlived the test and reports a
 * risky test, which `failOnRisky` turns into a failed build. Any test case that boots a real
 * kernel needs this, not only the ones that go through `AuditTrailKernelTestCase`.
 *
 * Only the handlers the kernel put there are popped, and only while they are still on top:
 * restoring blindly, or a fixed number of times, would take PHPUnit's own handler with them,
 * which it reports just as loudly. That also makes the unwinding indifferent to how many
 * kernels a test booted.
 */
trait RestoresFrameworkErrorHandlers
{
    protected function restoreFrameworkErrorHandlers(): void
    {
        while (self::isFrameworkErrorHandler(self::peekErrorHandler())) {
            restore_error_handler();
        }

        while (self::isFrameworkErrorHandler(self::peekExceptionHandler())) {
            restore_exception_handler();
        }
    }

    /**
     * Reads the handler on top of the stack without adding one: `set_*_handler()` returns the
     * previous handler, and the matching `restore_*_handler()` undoes the push it just made.
     */
    private static function peekErrorHandler(): mixed
    {
        $handler = set_error_handler(null);
        restore_error_handler();

        return $handler;
    }

    private static function peekExceptionHandler(): mixed
    {
        $handler = set_exception_handler(null);
        restore_exception_handler();

        return $handler;
    }

    private static function isFrameworkErrorHandler(mixed $handler): bool
    {
        return \is_array($handler) && ($handler[0] ?? null) instanceof ErrorHandler;
    }
}
