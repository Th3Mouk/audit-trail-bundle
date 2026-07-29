<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Model;

/**
 * A reference to another object: what kind of thing it is, which one, and what it was called.
 *
 * `type` is a short name — `role`, `organization` — never a class name. A class name is the one part
 * of an entry that does not survive a refactor, and it would leak the application's namespace layout
 * into every payload that mentions a reference.
 *
 * The label is snapshotted at capture time on purpose: an audit entry must stay
 * legible after the referenced row is renamed or deleted. This is what turns
 * "role 4217 removed" into "role Manager removed from Jean Dupont".
 */
final readonly class AuditRef implements \JsonSerializable
{
    public function __construct(
        public string $type,
        public string $id,
        public ?string $label = null,
    ) {
    }

    public static function of(string $type, string|int $id, ?string $label = null): self
    {
        return new self($type, (string) $id, $label);
    }

    /**
     * @return array{type: string, id: string, label: string|null}
     */
    public function jsonSerialize(): array
    {
        return [
            'type' => $this->type,
            'id' => $this->id,
            'label' => $this->label,
        ];
    }

    /**
     * @param array{type?: string, id?: string|int, label?: string|null} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['type'] ?? ''),
            (string) ($data['id'] ?? ''),
            $data['label'] ?? null,
        );
    }
}
