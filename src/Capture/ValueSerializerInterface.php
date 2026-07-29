<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Capture;

/**
 * Turns one captured value into something JSON can hold.
 *
 * Serializers form a priority chain; the first that supports a value wins. The bundle
 * ships handlers for scalars, dates, enums, stringables and entity references. Add your
 * own with the `audit_trail.value_serializer` tag to teach it a value object, a money
 * type, or a redaction rule.
 */
interface ValueSerializerInterface
{
    public function supports(mixed $value): bool;

    public function serialize(mixed $value): mixed;
}
