<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Model;

/**
 * A reference to another object, captured with a human-readable label.
 *
 * The label is snapshotted at capture time on purpose: an audit entry must stay
 * legible after the referenced row is renamed or deleted. This is what turns
 * "role 4217 removed" into "role Manager removed from Jean Dupont".
 */
final readonly class AuditRef implements \JsonSerializable
{
    public function __construct(
        public string $class,
        public string $id,
        public ?string $label = null,
    ) {
    }

    public static function of(string $class, string|int $id, ?string $label = null): self
    {
        return new self($class, (string) $id, $label);
    }

    /**
     * @return array{class: string, id: string, label: string|null}
     */
    public function jsonSerialize(): array
    {
        return [
            'class' => $this->class,
            'id' => $this->id,
            'label' => $this->label,
        ];
    }

    /**
     * @param array{class?: string, id?: string|int, label?: string|null} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['class'] ?? ''),
            (string) ($data['id'] ?? ''),
            $data['label'] ?? null,
        );
    }
}
