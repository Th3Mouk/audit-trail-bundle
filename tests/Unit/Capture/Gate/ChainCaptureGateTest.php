<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Unit\Capture\Gate;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Th3Mouk\AuditTrail\Capture\CaptureGateInterface;
use Th3Mouk\AuditTrail\Capture\Gate\ChainCaptureGate;
use Th3Mouk\AuditTrail\Enum\AuditAction;
use Th3Mouk\AuditTrail\Tests\Case\AuditTrailTestCase;
use Th3Mouk\AuditTrail\Tests\Fixtures\Double\FakeCaptureGate;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\Post;

#[CoversClass(ChainCaptureGate::class)]
final class ChainCaptureGateTest extends AuditTrailTestCase
{
    /**
     * @param list<CaptureGateInterface> $gates
     */
    #[DataProvider('assemblies')]
    public function testAChangeIsCapturedOnlyWhenEveryGateAgrees(array $gates, AuditAction $action, bool $expected): void
    {
        self::assertSame($expected, (new ChainCaptureGate($gates))->shouldCapture(new Post('Autumn'), $action));
    }

    /**
     * @return iterable<string, array{list<CaptureGateInterface>, AuditAction, bool}>
     */
    public static function assemblies(): iterable
    {
        yield 'nobody objects because nobody is there' => [[], AuditAction::Update, true];

        yield 'every gate agrees' => [
            [FakeCaptureGate::allowingEverything(), FakeCaptureGate::allowingEverything()],
            AuditAction::Update,
            true,
        ];

        yield 'a single dissenting voice is enough' => [
            [FakeCaptureGate::allowingEverything(), FakeCaptureGate::refusingEverything()],
            AuditAction::Update,
            false,
        ];

        yield 'the dissenting voice comes first' => [
            [FakeCaptureGate::refusingEverything(), FakeCaptureGate::allowingEverything()],
            AuditAction::Update,
            false,
        ];

        yield 'a gate that only objects to this class' => [
            [FakeCaptureGate::refusing(Post::class)],
            AuditAction::Create,
            false,
        ];

        yield 'a gate that only objects to deletions, asked about a creation' => [
            [FakeCaptureGate::refusingAction(AuditAction::Delete)],
            AuditAction::Create,
            true,
        ];

        yield 'a gate that only objects to deletions, asked about a deletion' => [
            [FakeCaptureGate::refusingAction(AuditAction::Delete)],
            AuditAction::Delete,
            false,
        ];
    }
}
