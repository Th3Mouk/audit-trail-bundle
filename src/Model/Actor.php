<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Model;

use Th3Mouk\AuditTrail\Exception\InvalidActor;

/**
 * Who performed a change — on your terms.
 *
 * The bundle deliberately has no opinion on what principals exist in your application.
 * There is no built-in taxonomy to conform to: `type` is a free label you choose, and it
 * may be omitted entirely. Use it if you need to tell humans from machines, or ignore it.
 *
 * Every field is optional, because "we do not know who did this" is a legitimate and
 * common answer — a console command, a migration, a queue worker.
 *
 * The identifier is a string so principals with unrelated key types can coexist in one
 * trail. The label is a snapshot: it stays readable after a rename or a deletion.
 */
final readonly class Actor
{
    public const int MAX_TYPE_LENGTH = 32;
    public const int MAX_ID_LENGTH = 64;

    public function __construct(
        public ?string $id = null,
        public ?string $type = null,
        public ?string $label = null,
    ) {
        if (null !== $type && \strlen($type) > self::MAX_TYPE_LENGTH) {
            throw InvalidActor::typeTooLong($type, self::MAX_TYPE_LENGTH);
        }

        if (null !== $id && \strlen($id) > self::MAX_ID_LENGTH) {
            throw InvalidActor::idTooLong($id, self::MAX_ID_LENGTH);
        }
    }

    public static function of(string|int|null $id, ?string $type = null, ?string $label = null): self
    {
        return new self(null === $id ? null : (string) $id, $type, $label);
    }

    /**
     * No principal could be attributed — the change still happened.
     */
    public static function unknown(): self
    {
        return new self();
    }

    public function isKnown(): bool
    {
        return null !== $this->id || null !== $this->type || null !== $this->label;
    }
}
