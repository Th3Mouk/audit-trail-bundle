<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Metadata;

use Th3Mouk\AuditTrail\Attribute\Auditable;

/**
 * The short name an entity is known by in the trail, in place of its class.
 *
 * A fully qualified class name is the one piece of an entry that does not survive: rename or move the
 * class and every historical row points at something that no longer exists — in a table whose whole
 * purpose is to stay readable after the data is gone. It also leaks the application's namespace layout
 * to anyone reading the feed, and it is miserable in a URL.
 *
 * So entries are discriminated by a short type — `membership`, `invoice` — and the class name, when
 * there is one, is kept beside it as information rather than as a key. Nothing queries it.
 *
 * Declare the type and it is stable across any refactor:
 *
 *     #[Auditable(type: 'membership')]
 *
 * Leave it out and it is derived from the short class name in kebab-case, which is convenient and
 * *not* refactor-safe: renaming the class changes the type, and the history splits in two at the
 * rename. Convenience for a prototype, a declaration for anything you intend to keep.
 */
final class AuditTypeResolver
{
    /** @var array<class-string, string> */
    private array $resolved = [];

    /**
     * The type of a class, or a string that is already one.
     *
     * Callers hold whichever they have — a class name in application code, a type in a URL or a
     * report — so a string that is not a class is returned untouched rather than mangled.
     */
    public function typeOf(string $classOrType): string
    {
        if (!class_exists($classOrType)) {
            return $classOrType;
        }

        return $this->resolved[$classOrType] ??= self::declared($classOrType) ?? self::derive($classOrType);
    }

    /**
     * The type declared by the class itself, or null when it was left to derivation.
     *
     * Deliberately not inherited, unlike `enabled`. Whether to audit is a policy that a base class
     * can reasonably impose on its children; a *name* is not — two children inheriting one type
     * would merge their histories, which is the very thing {@see AuditTypeWarmer} refuses.
     * A subclass that genuinely wants its parent's type declares it too, and says so out loud.
     *
     * Reflection is all this needs, which is why the resolver has no dependencies: it is asked for
     * every captured entity and for every reference inside a payload.
     *
     * @param class-string $class
     */
    private static function declared(string $class): ?string
    {
        $attributes = new \ReflectionClass($class)->getAttributes(Auditable::class);

        return [] === $attributes ? null : $attributes[0]->newInstance()->type;
    }

    /**
     * The kebab-case of a class's short name: `OrganizationMembership` becomes
     * `organization-membership`.
     */
    public static function derive(string $class): string
    {
        $shortName = substr(strrchr('\\'.$class, '\\') ?: '', 1);

        return strtolower((string) preg_replace(
            ['/([a-z\d])([A-Z])/', '/([A-Z]+)([A-Z][a-z])/'],
            '$1-$2',
            $shortName,
        ));
    }
}
