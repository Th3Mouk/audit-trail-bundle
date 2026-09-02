<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Unit\Metadata;

use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Th3Mouk\AuditTrail\Exception\DuplicateAuditType;
use Th3Mouk\AuditTrail\Exception\InvalidAuditType;
use Th3Mouk\AuditTrail\Metadata\AuditableResolver;
use Th3Mouk\AuditTrail\Metadata\AuditTypeResolver;
use Th3Mouk\AuditTrail\Metadata\AuditTypeWarmer;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\PlainEntity;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\Post;
use Th3Mouk\AuditTrail\Tests\Unit\Support\Collision\AVeryLongClassNameVeryLongClassNameVeryLongClassNameVeryLongClassNameThatOverflowsTheColumn;
use Th3Mouk\AuditTrail\Tests\Unit\Support\Collision\EmptilyTypedInvoice;
use Th3Mouk\AuditTrail\Tests\Unit\Support\Collision\Invoice;
use Th3Mouk\AuditTrail\Tests\Unit\Support\Collision\Legacy\Invoice as LegacyInvoice;
use Th3Mouk\AuditTrail\Tests\Unit\Support\Collision\Legacy\SettledInvoice;
use Th3Mouk\AuditTrail\Tests\Unit\Support\Collision\NumericallyTypedInvoice;
use Th3Mouk\AuditTrail\Tests\Unit\Support\Collision\NumericallyTypedReceipt;
use Th3Mouk\AuditTrail\Tests\Unit\Support\Collision\OptedOut\Invoice as OptedOutInvoice;

/**
 * Two modules each having an `Invoice` is not a contrived example; it is the ordinary outcome of
 * deriving a type from a short class name. Merged histories are invisible once written, so the
 * collision has to stop the build.
 */
#[CoversClass(AuditTypeWarmer::class)]
#[CoversClass(DuplicateAuditType::class)]
#[CoversClass(InvalidAuditType::class)]
final class AuditTypeWarmerTest extends TestCase
{
    public function testTwoClassesClaimingOneTypeFailTheWarmup(): void
    {
        $this->expectException(DuplicateAuditType::class);
        $this->expectExceptionMessage('"invoice" is claimed by');
        $this->expectExceptionMessage(Invoice::class);
        $this->expectExceptionMessage(LegacyInvoice::class);

        $this->warmerFor(Invoice::class, LegacyInvoice::class)->warmUp('/tmp');
    }

    public function testDeclaringADistinctTypeSettlesIt(): void
    {
        self::assertSame([], $this->warmerFor(Invoice::class, SettledInvoice::class)->warmUp('/tmp'));
    }

    /**
     * The guard is about the trail, not about the schema: classes nobody audits may share a name,
     * and a child that opted back out never writes an entry to collide with.
     */
    public function testClassesThatAreNotAuditedAreNoneOfItsBusiness(): void
    {
        self::assertSame([], $this->warmerFor(Post::class, PlainEntity::class)->warmUp('/tmp'));
        self::assertSame([], $this->warmerFor(Invoice::class, OptedOutInvoice::class)->warmUp('/tmp'));
    }

    /**
     * PHP turns a numeric-looking string key into an int, so a type of `'2024'` would arrive at the
     * exception as an int and fail on the way in. Grouping carries the type as a value for this.
     */
    public function testATypeThatLooksLikeANumberIsStillReported(): void
    {
        $this->expectException(DuplicateAuditType::class);
        $this->expectExceptionMessage('"2024" is claimed by');

        $this->warmerFor(NumericallyTypedInvoice::class, NumericallyTypedReceipt::class)->warmUp('/tmp');
    }

    /**
     * A type is a short name, and the column says how short. Checked here so it cannot be an SQL
     * error on whichever flush first happens to touch that entity.
     */
    public function testATypeTooLongForTheColumnFailsTheWarmup(): void
    {
        $this->expectException(InvalidAuditType::class);
        $this->expectExceptionMessage('and the column holds 64');

        $this->warmerFor(AVeryLongClassNameVeryLongClassNameVeryLongClassNameVeryLongClassNameThatOverflowsTheColumn::class)->warmUp('/tmp');
    }

    public function testATypeDeclaredAsAnEmptyStringFailsTheWarmup(): void
    {
        $this->expectException(InvalidAuditType::class);
        $this->expectExceptionMessage('is an empty string');

        $this->warmerFor(EmptilyTypedInvoice::class)->warmUp('/tmp');
    }

    /**
     * An optional warmer only runs when asked, which is precisely how the collision would reach
     * production unnoticed.
     */
    public function testItIsNotOptional(): void
    {
        self::assertFalse($this->warmerFor(Post::class)->isOptional());
    }

    /**
     * @param class-string ...$classes
     */
    private function warmerFor(string ...$classes): AuditTypeWarmer
    {
        $driver = $this->createStub(MappingDriver::class);
        $driver->method('getAllClassNames')->willReturn($classes);

        $configuration = $this->createStub(Configuration::class);
        $configuration->method('getMetadataDriverImpl')->willReturn($driver);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getConfiguration')->willReturn($configuration);

        return new AuditTypeWarmer(
            $entityManager,
            new AuditTypeResolver(),
            new AuditableResolver($entityManager),
        );
    }
}
