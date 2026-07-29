<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Context;

use Symfony\Contracts\Service\ResetInterface;

/**
 * The ambient "where did this come from" of the current unit of work.
 *
 * Two facts only, both optional: a correlation identifier that ties a trail entry back to
 * the request, job or trace that produced it, and the client address. Anything richer
 * belongs in an entry's metadata.
 *
 * Nothing here knows about HTTP. A listener fills it from a Symfony request, a console
 * command or a message handler can fill it just as well.
 *
 * The holder is resettable and must be registered with the `kernel.reset` tag, so a
 * long-running worker starts every message with an empty context instead of inheriting
 * the previous one.
 */
final class RequestContext implements ResetInterface
{
    private ?string $requestId = null;
    private ?string $clientIp = null;

    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    public function setRequestId(?string $requestId): void
    {
        $this->requestId = $requestId;
    }

    public function getClientIp(): ?string
    {
        return $this->clientIp;
    }

    public function setClientIp(?string $clientIp): void
    {
        $this->clientIp = $clientIp;
    }

    public function reset(): void
    {
        $this->requestId = null;
        $this->clientIp = null;
    }
}
