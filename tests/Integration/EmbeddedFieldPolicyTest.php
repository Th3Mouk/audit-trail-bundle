<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use Th3Mouk\AuditTrail\Enum\AuditAction;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\Certificate;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\Credentials;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\LooselyTypedVault;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\Signature;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\Vault;

/**
 * The field policies of an embeddable, through a real UnitOfWork.
 *
 * Doctrine reports an embedded change under a dotted key that is not a property of the owning
 * class, so a resolver reading the name literally finds no attribute and treats the field as
 * tracked. The consequence is not a missing row: it is a masked secret written out in clear. All
 * three operations are covered, because create and delete build their payload from the entity's
 * whole state rather than from a change set and could each regress independently.
 *
 * The secret is asserted absent from the entire table, not merely from its own field.
 */
#[CoversNothing]
final class EmbeddedFieldPolicyTest extends IntegrationTestCase
{
    private const string SECRET = 'correct-horse-battery-staple';
    private const string ROTATED_SECRET = 'a-completely-different-secret';
    private const string FINGERPRINT = 'sha256:aaaa';
    private const string MASK = '********';
    private const string PRIVATE_KEY = 'a-private-key-that-must-never-be-stored';

    public function testCreatingRecordsTheEmbeddedPolicies(): void
    {
        $this->save($this->vault());

        $entry = $this->assertOneEntry(AuditAction::Create, Vault::class);

        // A creation records flat state rather than before/after pairs, so the mask is the value.
        $this->assertFieldRecorded($entry, 'credentials.secret', self::MASK);
        $this->assertFieldRecorded($entry, 'credentials.username', 'operator');
        $this->assertFieldNotRecorded($entry, 'credentials.fingerprint');
        self::assertStringNotContainsString(self::SECRET, $this->auditTableDump());
    }

    public function testUpdatingAnEmbeddedSecretRecordsTheEventWithoutEitherValue(): void
    {
        $vault = $this->vault();
        $this->given($vault);

        $vault->getCredentials()->rotate(self::ROTATED_SECRET, 'sha256:bbbb');
        $this->em()->flush();

        $entry = $this->assertOneEntry(AuditAction::Update, Vault::class);

        $this->assertFieldMasked($entry, 'credentials.secret');
        $this->assertFieldNotRecorded($entry, 'credentials.fingerprint');

        $dump = $this->auditTableDump();
        self::assertStringNotContainsString(self::SECRET, $dump);
        self::assertStringNotContainsString(self::ROTATED_SECRET, $dump);
    }

    public function testChangingOnlyAnIgnoredEmbeddedFieldWritesNoRow(): void
    {
        $vault = $this->vault();
        $this->given($vault);

        $vault->getCredentials()->rotate(self::SECRET, 'sha256:cccc');
        $this->em()->flush();

        $this->assertNothingRecorded();
    }

    public function testDeletingKeepsTheEmbeddedSecretOutOfTheStateAtDelete(): void
    {
        $vault = $this->vault();
        $this->given($vault);

        $this->em()->remove($vault);
        $this->em()->flush();

        $entry = $this->assertOneEntry(AuditAction::Delete, Vault::class);

        $this->assertFieldRecorded($entry, 'credentials.secret', self::MASK);
        $this->assertFieldNotRecorded($entry, 'credentials.fingerprint');
        self::assertSame(
            'Production vault',
            $entry->entityLabel,
            'A deletion must still be legible: the label is what names the row that no longer exists.',
        );
        self::assertStringNotContainsString(self::SECRET, $this->auditTableDump());
    }

    /**
     * The same three policies, reached through properties Doctrine types and PHP does not.
     *
     * `#[ORM\Embedded(class: …)]` is a complete declaration on its own, so this entity is ordinary
     * Doctrine — and a policy lookup that learns the embeddable's class from the property's PHP type
     * learns nothing about it. Failing open there writes the secret out in clear.
     */
    public function testAnEmbeddableDeclaredWithoutAPhpTypeKeepsItsPolicies(): void
    {
        $this->save($this->looselyTypedVault());

        $entry = $this->assertOneEntry(AuditAction::Create, LooselyTypedVault::class);

        $this->assertFieldRecorded($entry, 'credentials.secret', self::MASK);
        $this->assertFieldRecorded($entry, 'credentials.username', 'operator');
        $this->assertFieldNotRecorded($entry, 'credentials.fingerprint');
        self::assertStringNotContainsString(self::SECRET, $this->auditTableDump());
    }

    public function testAnEmbeddableNestedInsideAnotherKeepsItsPolicies(): void
    {
        $this->save($this->looselyTypedVault());

        $entry = $this->assertOneEntry(AuditAction::Create, LooselyTypedVault::class);

        $this->assertFieldRecorded($entry, 'certificate.signature.privateKey', self::MASK);
        $this->assertFieldRecorded($entry, 'certificate.serial', 'X-1');
        self::assertStringNotContainsString(self::PRIVATE_KEY, $this->auditTableDump());
    }

    public function testRotatingAnUntypedEmbeddedSecretRecordsNeitherValue(): void
    {
        $vault = $this->looselyTypedVault();
        $this->given($vault);

        $vault->getCredentials()->rotate(self::ROTATED_SECRET, 'sha256:bbbb');
        $vault->getCertificate()->getSignature()->reissue(self::ROTATED_SECRET);
        $this->em()->flush();

        $entry = $this->assertOneEntry(AuditAction::Update, LooselyTypedVault::class);

        $this->assertFieldMasked($entry, 'credentials.secret');
        $this->assertFieldMasked($entry, 'certificate.signature.privateKey');
        $this->assertFieldNotRecorded($entry, 'credentials.fingerprint');

        $dump = $this->auditTableDump();
        self::assertStringNotContainsString(self::SECRET, $dump);
        self::assertStringNotContainsString(self::ROTATED_SECRET, $dump);
        self::assertStringNotContainsString(self::PRIVATE_KEY, $dump);
    }

    public function testDeletingAnUntypedEmbeddedSecretKeepsItOutOfTheStateAtDelete(): void
    {
        $vault = $this->looselyTypedVault();
        $this->given($vault);

        $this->em()->remove($vault);
        $this->em()->flush();

        $entry = $this->assertOneEntry(AuditAction::Delete, LooselyTypedVault::class);

        $this->assertFieldRecorded($entry, 'credentials.secret', self::MASK);
        $this->assertFieldRecorded($entry, 'certificate.signature.privateKey', self::MASK);
        $this->assertFieldNotRecorded($entry, 'credentials.fingerprint');

        $dump = $this->auditTableDump();
        self::assertStringNotContainsString(self::SECRET, $dump);
        self::assertStringNotContainsString(self::PRIVATE_KEY, $dump);
    }

    private function looselyTypedVault(): LooselyTypedVault
    {
        return new LooselyTypedVault(
            'Untyped vault',
            new Credentials('operator', self::SECRET, self::FINGERPRINT),
            new Certificate('X-1', new Signature('Positive CA', self::PRIVATE_KEY)),
        );
    }

    private function vault(): Vault
    {
        return new Vault(
            'Production vault',
            new Credentials('operator', self::SECRET, self::FINGERPRINT),
        );
    }
}
