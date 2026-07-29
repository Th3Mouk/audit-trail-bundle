# Installation

## 1. Require the package

```bash
composer require th3mouk/audit-trail-bundle
```

The only hard requirements are PHP 8.4+, Doctrine ORM 3 / DBAL 4, doctrine-bundle and a few
Symfony components. `api-platform/core`, `gedmo/doctrine-extensions` and
`symfony/security-bundle` are optional: the bundle boots, and its container compiles, with none of
them installed. See [bridges/api-platform.md](bridges/api-platform.md),
[bridges/gedmo.md](bridges/gedmo.md) and [actor.md](actor.md).

## 2. Register the bundle

With Symfony Flex, the bundle is enabled for you. Otherwise add it to `config/bundles.php`:

```php
return [
    // ...
    Th3Mouk\AuditTrail\AuditTrailBundle::class => ['all' => true],
];
```

No configuration file is required — every option has a default. See
[configuration.md](configuration.md).

## 3. What the bundle maps for you

The bundle ships one Doctrine entity, `Th3Mouk\AuditTrail\Entity\AuditLog`, and registers its
mapping itself by prepending to the `doctrine` extension. You do **not** add a mapping entry:

```php
// what the bundle prepends, for reference only — do not copy this into your config
$container->extension('doctrine', [
    'orm' => [
        'entity_managers' => [
            // your default manager, the first one you declare, or `audit_trail.entity_manager`
            'default' => [
                'mappings' => [
                    'AuditTrail' => [
                        'type' => 'attribute',
                        'is_bundle' => false,
                        'dir' => '%kernel.project_dir%/vendor/th3mouk/audit-trail-bundle/src/Entity',
                        'prefix' => 'Th3Mouk\AuditTrail\Entity',
                        'alias' => 'AuditTrail',
                    ],
                ],
            ],
        ],
    ],
]);
```

The entity is mapped `readOnly: true`: Doctrine never computes an update change set for a trail
row, because nothing is supposed to edit one.

### The `uuid` DBAL type

The primary key is a UUID v7 mapped with `Symfony\Bridge\Doctrine\Types\UuidType`. doctrine-bundle
does not register that type on its own, so if your application does not already use it, add:

```php
// config/packages/doctrine.php
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->extension('doctrine', [
        'dbal' => ['types' => [UuidType::NAME => UuidType::class]],
    ]);
};
```

## 4. Create the table

Because the bundle ships an *entity* rather than a migration, the table is created by your
application's own migration — which is what you want: it lands in your migration history, on your
schedule, reviewed like any other schema change.

```bash
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate
```

The generated migration will contain something equivalent to this (PostgreSQL, from the real
mapping):

```sql
CREATE TABLE audit_logs (
    id UUID NOT NULL,
    actor_type VARCHAR(32) DEFAULT NULL,
    actor_id VARCHAR(64) DEFAULT NULL,
    actor_label VARCHAR(255) DEFAULT NULL,
    entity_type VARCHAR(64) NOT NULL,
    entity_class VARCHAR(255) DEFAULT NULL,
    entity_id VARCHAR(64) NOT NULL,
    entity_label VARCHAR(255) DEFAULT NULL,
    action VARCHAR(16) NOT NULL,
    changes JSON DEFAULT NULL,
    root_type VARCHAR(64) DEFAULT NULL,
    root_id VARCHAR(64) DEFAULT NULL,
    root_label VARCHAR(255) DEFAULT NULL,
    occurred_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
    request_id VARCHAR(64) DEFAULT NULL,
    ip VARCHAR(45) DEFAULT NULL,
    metadata JSON DEFAULT NULL,
    PRIMARY KEY (id)
);
CREATE INDEX idx_audit_entity   ON audit_logs (entity_type, entity_id, occurred_at, id);
CREATE INDEX idx_audit_root     ON audit_logs (root_type, root_id, occurred_at, id);
CREATE INDEX idx_audit_actor    ON audit_logs (actor_type, actor_id, occurred_at, id);
CREATE INDEX idx_audit_occurred ON audit_logs (occurred_at, id);
```

The four indexes are the four questions the trail is meant to answer, and they are the reason
those columns are denormalised in the first place:

| Index | Question |
| --- | --- |
| `idx_audit_entity` | what happened to *this row* |
| `idx_audit_root` | what happened to *this aggregate* ([aggregate-history.md](aggregate-history.md)) |
| `idx_audit_actor` | what did *this principal* do ([actor.md](actor.md)) |
| `idx_audit_occurred` | what happened *in this window*, and retention pruning |

On MySQL the identifier column comes out as `BINARY(16)` and `occurred_at` as `DATETIME`; the
indexes are declared inline in the `CREATE TABLE`. Everything else is identical.

## 5. Choosing a table name

`audit_logs` is the default. If the name is taken, or your conventions differ:

```php
// config/packages/audit_trail.php
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->extension('audit_trail', ['table_name' => 'platform_audit_logs']);
};
```

The mapping is rewritten at runtime (a `loadClassMetadata` listener), so the change is picked up by
`doctrine:migrations:diff` like any other mapping change. Renaming an existing table is a migration
you write yourself.

## 6. PostgreSQL: `jsonb` for `changes` and `metadata`

The entity maps both JSON columns with Doctrine's portable `json` type, which PostgreSQL renders as
`JSON`. If you intend to query inside the payload — `changes -> 'title' ->> 'after'`,
`metadata @> '{"origin":"dql"}'` — you almost certainly want `jsonb`, which is indexable and
canonicalised. Edit the generated migration:

```php
public function up(Schema $schema): void
{
    // ... the CREATE TABLE from the diff, then:
    $this->addSql('ALTER TABLE audit_logs ALTER COLUMN changes  TYPE JSONB USING changes::jsonb');
    $this->addSql('ALTER TABLE audit_logs ALTER COLUMN metadata TYPE JSONB USING metadata::jsonb');
}
```

This is stable, not a fight with Doctrine: DBAL's PostgreSQL platform maps a `jsonb` column back to
the `json` Doctrine type on introspection, so a later `doctrine:migrations:diff` does not propose
undoing it. Nothing in the bundle reads these columns as anything other than an array.

## Next

- [attributes.md](attributes.md) — mark your first entity.
- [configuration.md](configuration.md) — the full option tree.
