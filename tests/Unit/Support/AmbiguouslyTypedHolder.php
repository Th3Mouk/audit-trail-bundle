<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Unit\Support;

use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\Credentials;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\Signature;

/**
 * Three ways a property can hold an embeddable without naming its class in a PHP type.
 *
 * Doctrine is content with all of them — `#[ORM\Embedded(class: …)]` is the declaration — so a
 * resolver that reads the class off the type has to have an answer for each that is not "record the
 * value and hope".
 */
final class AmbiguouslyTypedHolder
{
    public \Stringable $viaInterface;

    public Credentials|Signature $viaUnion;

    /** @var Credentials */
    public $untyped;
}
