<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Unit\Capture;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Th3Mouk\AuditTrail\Capture\ChainFieldExclusion;
use Th3Mouk\AuditTrail\Tests\Case\AuditTrailTestCase;
use Th3Mouk\AuditTrail\Tests\Fixtures\Double\FakeFieldExclusion;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\Post;

/**
 * A union, not a first-answer-wins chain.
 *
 * An exclusion only ever removes a field from an entry, so consulting every contributor cannot
 * smuggle anything in — and stopping at the first one that answered would silently keep a field
 * a later contributor knows is diverted elsewhere.
 */
#[CoversClass(ChainFieldExclusion::class)]
final class ChainFieldExclusionTest extends AuditTrailTestCase
{
    public function testAnEmptyChainExcludesNothing(): void
    {
        self::assertSame([], (new ChainFieldExclusion())->excludedFields($this->anEntityManager(), new Post('Autumn')));
    }

    public function testEveryContributorIsConsultedAndTheAnswersAreMerged(): void
    {
        $chain = new ChainFieldExclusion([
            FakeFieldExclusion::excluding('title'),
            FakeFieldExclusion::excludingNothing(),
            FakeFieldExclusion::excluding('body'),
        ]);

        $excluded = $chain->excludedFields($this->anEntityManager(), new Post('Autumn'));
        sort($excluded);

        self::assertSame(['body', 'title'], $excluded);
    }

    public function testTwoContributorsNamingTheSameFieldIsNotAConflict(): void
    {
        $chain = new ChainFieldExclusion([
            FakeFieldExclusion::excluding('title', 'body'),
            FakeFieldExclusion::excluding('body'),
        ]);

        $excluded = $chain->excludedFields($this->anEntityManager(), new Post('Autumn'));
        sort($excluded);

        self::assertSame(['body', 'title'], $excluded);
    }

    /**
     * A stub, not a mock: the chain hands the manager straight through to its contributors and
     * never asks it anything, which is exactly the contract worth keeping.
     */
    private function anEntityManager(): EntityManagerInterface
    {
        return $this->createStub(EntityManagerInterface::class);
    }
}
