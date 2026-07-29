<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Bridge\Gedmo;

use Gedmo\Mapping\Annotation\SoftDeleteable;
use Th3Mouk\AuditTrail\Bridge\Gedmo\SoftDeleteableActionResolver;
use Th3Mouk\AuditTrail\Enum\AuditAction;
use Th3Mouk\AuditTrail\Tests\Bridge\Gedmo\Fixture\RestorableSoftDeleteablePage;
use Th3Mouk\AuditTrail\Tests\Case\AuditTrailTestCase;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\PlainEntity;

/**
 * The change-set reading behind the soft-delete mapping.
 *
 * Worth asserting on its own because the case that matters most in real applications —
 * `setDeletedAt(new \DateTimeImmutable())` with no `remove()` at all — reaches the resolver as
 * an ordinary update and no listener ordering can rescue it.
 */
final class SoftDeleteableActionResolverTest extends AuditTrailTestCase
{
    private SoftDeleteableActionResolver $resolver;

    protected function setUp(): void
    {
        if (!class_exists(SoftDeleteable::class)) {
            self::markTestSkipped('gedmo/doctrine-extensions is not installed: the soft-delete mapping has no configuration to read.');
        }

        parent::setUp();

        $this->resolver = new SoftDeleteableActionResolver();
    }

    public function testAHandMadeLogicalDeleteIsADeletion(): void
    {
        $changeSet = ['deletedAt' => [null, $this->anInstant()]];

        self::assertSame(AuditAction::Delete, $this->resolver->resolveAction($this->aPage(), $changeSet));
        self::assertTrue($this->resolver->isLogicalDelete($this->aPage(), $changeSet));
        self::assertSame(SoftDeleteableActionResolver::TRANSITION_DELETE, $this->resolver->resolveTransition($this->aPage(), $changeSet));
    }

    public function testClearingTheDateIsARestoreAndReadsAsAnUpdate(): void
    {
        $changeSet = ['deletedAt' => [$this->anInstant(), null]];

        self::assertSame(AuditAction::Update, $this->resolver->resolveAction($this->aPage(), $changeSet));
        self::assertTrue($this->resolver->isRestore($this->aPage(), $changeSet));
        self::assertSame(SoftDeleteableActionResolver::TRANSITION_RESTORE, $this->resolver->resolveTransition($this->aPage(), $changeSet));
    }

    public function testMovingTheDateElsewhereIsAnOrdinaryUpdate(): void
    {
        $changeSet = ['deletedAt' => [$this->anInstant('2026-01-01 00:00:00'), $this->anInstant('2026-03-01 00:00:00')]];

        self::assertNull($this->resolver->resolveAction($this->aPage(), $changeSet));
        self::assertNull($this->resolver->resolveTransition($this->aPage(), $changeSet));
    }

    /**
     * A documented limit: the entry records the moment the deletion date was set, which may
     * precede the moment it takes effect.
     */
    public function testAFutureDeletionDateIsAlreadyADeletion(): void
    {
        $changeSet = ['deletedAt' => [null, $this->anInstant('2099-01-01 00:00:00')]];

        self::assertSame(AuditAction::Delete, $this->resolver->resolveAction($this->aPage(), $changeSet));
    }

    public function testAChangeSetWithoutTheDateFieldDecidesNothing(): void
    {
        self::assertNull($this->resolver->resolveAction($this->aPage(), ['title' => ['Before', 'After']]));
    }

    public function testAnEntityWithoutTheAttributeIsNotSoftDeleteable(): void
    {
        $entity = new PlainEntity('not soft deleteable');

        self::assertFalse($this->resolver->isSoftDeleteable(PlainEntity::class));
        self::assertNull($this->resolver->deletedAtField(PlainEntity::class));
        self::assertNull($this->resolver->resolveAction($entity, ['deletedAt' => [null, $this->anInstant()]]));
    }

    public function testTheDateFieldIsReadFromTheEntitysOwnConfiguration(): void
    {
        self::assertTrue($this->resolver->isSoftDeleteable(RestorableSoftDeleteablePage::class));
        self::assertSame('deletedAt', $this->resolver->deletedAtField(RestorableSoftDeleteablePage::class));
    }

    private function aPage(): RestorableSoftDeleteablePage
    {
        return new RestorableSoftDeleteablePage('A page');
    }
}
