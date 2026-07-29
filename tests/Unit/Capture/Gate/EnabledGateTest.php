<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Unit\Capture\Gate;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Th3Mouk\AuditTrail\Capture\Gate\EnabledGate;
use Th3Mouk\AuditTrail\Enum\AuditAction;
use Th3Mouk\AuditTrail\Tests\Case\AuditTrailTestCase;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\Post;

#[CoversClass(EnabledGate::class)]
final class EnabledGateTest extends AuditTrailTestCase
{
    #[DataProvider('actions')]
    public function testAnEnabledTrailCapturesEveryKindOfChange(AuditAction $action): void
    {
        self::assertTrue($this->shouldCapture(new EnabledGate(), $action));
    }

    #[DataProvider('actions')]
    public function testADisabledTrailCapturesNothing(AuditAction $action): void
    {
        self::assertFalse($this->shouldCapture(new EnabledGate(false), $action));
    }

    /**
     * @return iterable<string, array{AuditAction}>
     */
    public static function actions(): iterable
    {
        yield 'a creation' => [AuditAction::Create];
        yield 'an update' => [AuditAction::Update];
        yield 'a deletion' => [AuditAction::Delete];
    }

    public function testABulkOperationCanSilenceTheTrailAndGiveItBack(): void
    {
        $gate = new EnabledGate();

        $gate->pause();

        self::assertTrue($gate->isPaused());
        self::assertFalse($this->shouldCapture($gate, AuditAction::Update));

        $gate->resume();

        self::assertFalse($gate->isPaused());
        self::assertTrue($this->shouldCapture($gate, AuditAction::Update));
    }

    public function testAJobThatPausesAndNeverResumesCannotSilenceTheNextOne(): void
    {
        $gate = new EnabledGate();
        $gate->pause();

        $gate->reset();

        self::assertFalse($gate->isPaused());
        self::assertTrue($this->shouldCapture($gate, AuditAction::Update));
    }

    public function testResumingCannotSwitchOnATrailTheConfigurationTurnedOff(): void
    {
        $gate = new EnabledGate(false);

        $gate->pause();
        $gate->resume();
        $gate->reset();

        self::assertFalse($this->shouldCapture($gate, AuditAction::Update));
    }

    private function shouldCapture(EnabledGate $gate, AuditAction $action): bool
    {
        return $gate->shouldCapture(new Post('Autumn'), $action);
    }
}
