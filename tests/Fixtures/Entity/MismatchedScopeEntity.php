<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Fixtures\Entity;

use Th3Mouk\AuditTrail\Attribute\Auditable;
use Th3Mouk\AuditTrail\Attribute\AuditScope;

/**
 * Declares one root and walks to another.
 *
 * Not an ORM entity on purpose: the mismatch is a metadata fact, provable without a
 * database, and mapping it would only add a table nothing writes to.
 */
#[Auditable]
#[AuditScope(root: Author::class, via: 'post')]
class MismatchedScopeEntity
{
    public function __construct(private readonly Post $post)
    {
    }

    public function getPost(): Post
    {
        return $this->post;
    }
}
