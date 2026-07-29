<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Capture\Value;

use Doctrine\Persistence\Proxy;
use Th3Mouk\AuditTrail\Capture\ValueSerializerInterface;

/**
 * The last-resort handler for value objects that already know how to render themselves.
 *
 * It sits at the end of the chain: an object that is both a mapped entity and stringable must be
 * captured as a reference, not flattened to a string. Uninitialised lazy objects are declined
 * outright, since casting one would issue a query in the middle of the flush.
 */
final readonly class StringableValueSerializer implements ValueSerializerInterface
{
    public function supports(mixed $value): bool
    {
        return $value instanceof \Stringable && !$this->isUninitialised($value);
    }

    public function serialize(mixed $value): mixed
    {
        return $value instanceof \Stringable && !$this->isUninitialised($value) ? (string) $value : null;
    }

    private function isUninitialised(object $value): bool
    {
        if ($value instanceof Proxy) {
            return !$value->__isInitialized();
        }

        return (new \ReflectionClass($value))->isUninitializedLazyObject($value);
    }
}
