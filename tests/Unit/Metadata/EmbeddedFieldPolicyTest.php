<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Unit\Metadata;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\EmbeddedClassMapping;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Th3Mouk\AuditTrail\Metadata\FieldPolicy;
use Th3Mouk\AuditTrail\Metadata\FieldPolicyResolver;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\Certificate;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\Credentials;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\LooselyTypedVault;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\Signature;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\Vault;
use Th3Mouk\AuditTrail\Tests\Unit\Support\AmbiguouslyTypedHolder;

/**
 * A field policy declared inside an embeddable must still apply.
 *
 * Doctrine reports these changes under a dotted key, which is not a property of the owning class.
 * Resolving it literally yields nothing, and the failure mode of getting this wrong is not a missing
 * row: it is a masked secret written out in clear.
 */
final class EmbeddedFieldPolicyTest extends TestCase
{
    #[DataProvider('embeddedFields')]
    public function testThePolicyDeclaredOnTheEmbeddedPropertyIsTheOneApplied(
        string $changeSetKey,
        FieldPolicy $expected,
    ): void {
        self::assertSame($expected, new FieldPolicyResolver()->policyFor(Vault::class, $changeSetKey));
    }

    /**
     * @return iterable<string, array{string, FieldPolicy}>
     */
    public static function embeddedFields(): iterable
    {
        yield 'a property of the owning entity still resolves' => ['name', FieldPolicy::Tracked];
        yield 'an embedded property with no attribute is tracked' => ['credentials.username', FieldPolicy::Tracked];
        yield 'an embedded #[AuditMasked] property is masked' => ['credentials.secret', FieldPolicy::Masked];
        yield 'an embedded #[NotAuditable] property is ignored' => ['credentials.fingerprint', FieldPolicy::Ignored];
    }

    /**
     * The rule that turns every unknown of this class into a non-event.
     *
     * A dotted key is Doctrine talking about the inside of an embeddable, which is exactly where a
     * mask would be declared. Not being able to walk it is not a reason to publish the value.
     */
    /**
     * @param class-string $class
     */
    #[DataProvider('pathsNothingCanResolve')]
    public function testAPathThatCannotBeResolvedIsMaskedRatherThanRecorded(string $class, string $key): void
    {
        self::assertSame(FieldPolicy::Masked, new FieldPolicyResolver()->policyFor($class, $key));
    }

    /**
     * @return iterable<string, array{class-string, string}>
     */
    public static function pathsNothingCanResolve(): iterable
    {
        yield 'a leaf the embeddable does not declare' => [Vault::class, 'credentials.nope'];
        yield 'a first segment that is not a property' => [Vault::class, 'nope.secret'];
        yield 'a first segment that is a scalar' => [Vault::class, 'name.secret'];
        yield 'a property with no PHP type' => [LooselyTypedVault::class, 'credentials.secret'];
        yield 'a property typed by an interface' => [AmbiguouslyTypedHolder::class, 'viaInterface.secret'];
        yield 'a property with a union type' => [AmbiguouslyTypedHolder::class, 'viaUnion.secret'];
        yield 'an untyped property, two levels down' => [AmbiguouslyTypedHolder::class, 'untyped.secret'];
    }

    /**
     * A key with no dot is an ordinary property name, and an unannotated property is tracked — the
     * permissive default the whole design rests on. Masking those would empty the trail.
     */
    public function testAPlainKeyNobodyDeclaredIsStillTracked(): void
    {
        self::assertSame(FieldPolicy::Tracked, new FieldPolicyResolver()->policyFor(Vault::class, 'nope'));
    }

    /**
     * With Doctrine at hand, a PHP type is not needed at all: `embeddedClasses` carries the whole
     * dotted path, so the policy is read from the class the mapping names.
     */
    #[DataProvider('mappedEmbeddedFields')]
    public function testDoctrinesMappingResolvesWhatReflectionCannot(string $changeSetKey, FieldPolicy $expected): void
    {
        $resolver = new FieldPolicyResolver('********', $this->doctrineKnowing([
            'credentials' => Credentials::class,
            'certificate' => Certificate::class,
            'certificate.signature' => Signature::class,
        ]));

        self::assertSame($expected, $resolver->policyFor(LooselyTypedVault::class, $changeSetKey));
    }

    /**
     * @return iterable<string, array{string, FieldPolicy}>
     */
    public static function mappedEmbeddedFields(): iterable
    {
        yield 'an untyped embeddable, masked field' => ['credentials.secret', FieldPolicy::Masked];
        yield 'an untyped embeddable, tracked field' => ['credentials.username', FieldPolicy::Tracked];
        yield 'an untyped embeddable, ignored field' => ['credentials.fingerprint', FieldPolicy::Ignored];
        yield 'a nested untyped embeddable, masked field' => ['certificate.signature.privateKey', FieldPolicy::Masked];
        yield 'a nested untyped embeddable, tracked field' => ['certificate.signature.issuer', FieldPolicy::Tracked];
    }

    /**
     * Failing closed has to be quiet as well as safe.
     *
     * `$metadata?->embeddedClasses[$path]` warns on a path Doctrine never mapped, and an application
     * that promotes warnings to exceptions — a common, entirely reasonable setting — would then have
     * its flush aborted by the very branch whose job is to decide a policy and move on.
     */
    public function testAPathDoctrineNeverMappedFailsClosedWithoutRaising(): void
    {
        $resolver = new FieldPolicyResolver('********', $this->doctrineKnowing([
            'credentials' => Credentials::class,
        ]));

        set_error_handler(
            static fn (int $severity, string $message): never => throw new \ErrorException($message, severity: $severity),
        );

        try {
            self::assertSame(FieldPolicy::Masked, $resolver->policyFor(LooselyTypedVault::class, 'certificate.serial'));
        } finally {
            restore_error_handler();
        }
    }

    public function testTheMaskOfAnEmbeddedPropertyIsResolvedToo(): void
    {
        self::assertSame(
            '[hidden]',
            new FieldPolicyResolver('[hidden]')->maskFor(Vault::class, 'credentials.secret'),
        );
    }

    /**
     * @param array<string, class-string> $embeddedClasses
     */
    private function doctrineKnowing(array $embeddedClasses): EntityManagerInterface
    {
        $metadata = new ClassMetadata(LooselyTypedVault::class);

        foreach ($embeddedClasses as $path => $class) {
            $metadata->embeddedClasses[$path] = new EmbeddedClassMapping($class);
        }

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getClassMetadata')->willReturn($metadata);

        return $entityManager;
    }
}
