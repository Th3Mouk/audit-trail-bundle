<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Storage;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Tells the storage whether it is writing inside a flush or outside one.
 *
 * Doctrine offers no public way to ask "am I mid-flush?", and guessing from internals is
 * the kind of cleverness that breaks on a minor upgrade. So the capture listener simply
 * says so: it marks the flush on entry and clears it on the way out.
 *
 * Which makes this everyone's job, not one listener's: a mark is only held for the duration
 * of the callback that made it, and a flush outlives any single `onFlush` callback. Every
 * listener that records while Doctrine is committing has to mark the flush itself — the
 * bundle's own capture listener, the Gedmo translation bridge, and any application listener
 * doing the same. Recording unmarked is not a lost row but a wrong one; `DoctrineAuditStorage`
 * says why.
 *
 * The depth counter is what makes that composable, and it is needed anyway because a flush
 * can nest — a listener that flushes another entity manager, or a handler flushing inside a
 * flush event. Leaving the inner flush must not convince the storage that the outer one is
 * over.
 */
final class FlushState implements ResetInterface
{
    private int $depth = 0;

    public function enterFlush(): void
    {
        ++$this->depth;
    }

    public function leaveFlush(): void
    {
        $this->depth = max(0, $this->depth - 1);
    }

    public function isFlushing(): bool
    {
        return $this->depth > 0;
    }

    public function reset(): void
    {
        $this->depth = 0;
    }
}
