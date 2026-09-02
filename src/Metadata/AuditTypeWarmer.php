<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Metadata;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;
use Th3Mouk\AuditTrail\Entity\AuditLog;
use Th3Mouk\AuditTrail\Exception\DuplicateAuditType;
use Th3Mouk\AuditTrail\Exception\InvalidAuditType;

/**
 * Fails the build when a type would not survive contact with the database.
 *
 * Two things are checked, both of which are cheap here and expensive later: that every audited
 * entity's type fits the column and is not empty, and that no two of them claim the same one.
 *
 * A collision does not break anything visibly: it merges two unrelated histories, and every reader of
 * the trail is then wrong without being told. Two classes called `Invoice` in two modules is not a
 * contrived example — it is the ordinary outcome of deriving a type from a short class name, which is
 * exactly what happens when nobody declares one.
 *
 * Only opting-in matters here, not whether the entity is supported: asking that of every mapped
 * class would turn an unsupported one into a boot failure instead of the flush-time error it is.
 *
 * This runs as a cache warmer rather than a compiler pass because a compiler pass cannot enumerate
 * Doctrine's entities: there is no entity manager to ask while the container is still being built. A
 * warmer has one, and `cache:warmup` is the step a deployment already runs — so the check lands where
 * a build failure belongs, and never in the middle of a request.
 *
 * It asks the mapping driver for class names — `getAllClassNames()` — rather than asking the
 * metadata factory for `ClassMetadata` — `getAllMetadata()` — and that is not an optimisation, it is
 * what keeps this warmer from breaking DoctrineBundle's own one. DoctrineBundle registers its
 * `DoctrineMetadataCacheWarmer` at `kernel.cache_warmer` priority 1000 whenever `kernel.debug` is
 * false, and that warmer throws if the metadata factory has already loaded anything by the time it
 * runs, because it means to be the one populating the cache it then persists to disk. No priority on
 * this warmer's own tag can protect against that: the first warm-up pass a freshly compiled container
 * runs, as a side effect of `Kernel::initializeContainer()`, includes only warmers that are not
 * optional; `cache:warmup` and `cache:clear` each then run a *second*, separate pass that adds the
 * optional ones in, DoctrineBundle's included. Priority only orders warmers against one another within
 * a single one of those passes — it cannot move a non-optional warmer into the later one. Being
 * non-optional therefore guarantees this warmer always runs in the first pass, before DoctrineBundle's
 * ever gets a turn, so calling `getAllMetadata()` here would load metadata on DoctrineBundle's behalf
 * every time, regardless of what number is written on this tag. The driver's class list is the same
 * one `AbstractClassMetadataFactory::getAllMetadata()` reads before loading anything, so asking for it
 * directly answers the one question this warmer has — is a type claimed twice — without loading a
 * single `ClassMetadata`, leaving the factory exactly as untouched for DoctrineBundle's warmer as if
 * this one did not exist.
 */
final readonly class AuditTypeWarmer implements CacheWarmerInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AuditTypeResolver $typeResolver,
        private AuditableResolver $auditableResolver,
    ) {
    }

    /**
     * Never optional: a warmer that only runs on demand would let the collision reach production.
     */
    public function isOptional(): bool
    {
        return false;
    }

    /**
     * @return list<string>
     */
    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        /** @var array<string, array{type: string, classes: list<class-string>}> $claims */
        $claims = [];

        $driver = $this->entityManager->getConfiguration()->getMetadataDriverImpl();

        foreach ($driver?->getAllClassNames() ?? [] as $class) {
            if (!$this->auditableResolver->isEnabledFor($class)) {
                continue;
            }

            $type = $this->typeResolver->typeOf($class);

            self::assertUsable($class, $type);

            $scope = $this->auditableResolver->scopeFor($class);

            if (null !== $scope) {
                self::assertUsable($class, $scope->type ?? $this->typeResolver->typeOf($scope->root));
            }

            $claims[$type] ??= ['type' => $type, 'classes' => []];
            $claims[$type]['classes'][] = $class;
        }

        foreach ($claims as $claim) {
            if (\count($claim['classes']) > 1) {
                throw DuplicateAuditType::claimedBy($claim['type'], $claim['classes']);
            }
        }

        return [];
    }

    /**
     * The two ways a type is unusable before anything is ever written with it.
     */
    private static function assertUsable(string $class, string $type): void
    {
        if ('' === $type) {
            throw InvalidAuditType::empty($class);
        }

        if (mb_strlen($type) > AuditLog::TYPE_LENGTH) {
            throw InvalidAuditType::tooLong($class, $type, AuditLog::TYPE_LENGTH);
        }
    }
}
