<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Bridge\ApiPlatform\Metadata;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\ResourceMetadataCollection;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Th3Mouk\AuditTrail\Bridge\ApiPlatform\AuditFeedOptions;
use Th3Mouk\AuditTrail\Entity\AuditLog;

/**
 * Builds the read-only audit feed's resource metadata.
 *
 * Written in PHP rather than shipped as XML/YAML under `src/Resources/config/api_platform/`
 * because the feed is configuration-driven and the file extractors cannot express it:
 * `AbstractResourceExtractor::resolve()` only expands `%container.parameters%` for the resource
 * class and for uriVariables' from/to classes, so `uriTemplate`, `paginationItemsPerPage` and
 * `security` would be frozen literals; and the security attribute must be *absent* — not empty —
 * when the host configured none, which no static file can express. On top of that
 * `XmlResourceExtractor::buildPaginationViaCursor()` emits `['id' => 'DESC']` while
 * `PartialCollectionViewNormalizer` reads `[['field' => …, 'direction' => …]]`, so cursor
 * pagination declared in XML would never produce cursor links.
 *
 * Only GetCollection and Get are declared. Writes are not silenced with `NotExposed`: both URIs
 * exist as GET-only Symfony routes, so a POST/PATCH/PUT/DELETE matches the path but not the
 * method and the router answers 405 — a 404 would wrongly suggest the trail is not there.
 */
final readonly class AuditFeedResourceMetadataCollectionFactory implements ResourceMetadataCollectionFactoryInterface
{
    private const string SHORT_NAME = 'AuditLog';

    private const array DETAIL_ONLY_ATTRIBUTES = ['changes', 'metadata', 'ip', 'requestId'];

    private const string CURSOR_DIRECTION = 'DESC';

    private const string CURSOR_TIME_PROPERTY = 'occurredAt';

    private const string OPENAPI_TAG = 'Audit trail';

    private const string CURSOR_PROPERTY = 'id';

    /**
     * @param list<string> $filters service ids of the Doctrine ORM filters backing the feed
     */
    public function __construct(
        private ResourceMetadataCollectionFactoryInterface $decorated,
        private AuditFeedOptions $options,
        private array $filters,
    ) {
    }

    public function create(string $resourceClass): ResourceMetadataCollection
    {
        $resourceMetadataCollection = $this->decorated->create($resourceClass);

        if (AuditLog::class !== $resourceClass) {
            return $resourceMetadataCollection;
        }

        $resourceMetadataCollection[] = $this->auditFeed();

        return $resourceMetadataCollection;
    }

    private function auditFeed(): ApiResource
    {
        return new ApiResource(
            shortName: self::SHORT_NAME,
            description: 'Immutable audit trail entries: who changed what, and when.',
            operations: [
                $this->itemOperation(),
                $this->collectionOperation(),
            ],
            class: AuditLog::class,
            security: $this->options->security,
        );
    }

    private function itemOperation(): Get
    {
        return new Get(
            uriTemplate: $this->options->itemUriTemplate(),
            openapi: new OpenApiOperation(
                tags: [self::OPENAPI_TAG],
                summary: 'Read one audit entry in full',
                description: 'The complete entry, including the fields the collection omits: `changes`, '
                    .'`metadata`, `ip` and `requestId`.',
            ),
            shortName: self::SHORT_NAME,
            class: AuditLog::class,
            security: $this->options->security,
        );
    }

    private function collectionOperation(): GetCollection
    {
        return new GetCollection(
            uriTemplate: $this->options->collectionUriTemplate(),
            // Both halves of the sort key, so the hydra next/previous links carry a complete cursor.
            // KeysetCursorFilter reads the pair and turns it into one lexicographic predicate; two
            // independent range conditions would drop and duplicate rows.
            paginationViaCursor: [
                ['field' => self::CURSOR_TIME_PROPERTY, 'direction' => self::CURSOR_DIRECTION],
                ['field' => self::CURSOR_PROPERTY, 'direction' => self::CURSOR_DIRECTION],
            ],
            openapi: new OpenApiOperation(
                tags: [self::OPENAPI_TAG],
                summary: 'Browse the audit trail, newest first',
                description: 'Keyset-paginated: follow `hydra:view.next` rather than a page number — the '
                    .'cursor is a pair, `occurredAt[lt]` and `id[lt]`, and both halves are needed. The order is '
                    .'fixed at (occurredAt DESC, id DESC) because a cursor is only meaningful against the sort '
                    .'key it was issued for. Filter by aggregate (`rootType` + `rootId`) for a per-record '
                    .'history, by `entityType` (+ `entityId`) for one row, by `actorId` for one principal, or '
                    .'search the snapshotted labels with `actorLabel`, `entityLabel` and `rootLabel`. '
                    .'`entityType` is a short name such as `invoice`, declared by the entity or derived from '
                    .'its class name in kebab-case; class names are never query parameters, and `entityClass` '
                    .'is returned as information only. Heavy fields are omitted here; read one entry to get '
                    .'them.',
            ),
            shortName: self::SHORT_NAME,
            class: AuditLog::class,
            paginationItemsPerPage: $this->options->itemsPerPage,
            paginationMaximumItemsPerPage: $this->options->maxItemsPerPage,
            paginationPartial: true,
            paginationClientItemsPerPage: true,
            order: [self::CURSOR_TIME_PROPERTY => self::CURSOR_DIRECTION, self::CURSOR_PROPERTY => self::CURSOR_DIRECTION],
            normalizationContext: [AbstractNormalizer::IGNORED_ATTRIBUTES => self::DETAIL_ONLY_ATTRIBUTES],
            security: $this->options->security,
            filters: $this->filters,
        );
    }
}
