<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Context;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Fills the request context from the incoming HTTP request.
 *
 * Sub-requests are ignored: a trail entry belongs to the request the client made, not to
 * the fragment that happened to be rendering when the flush occurred.
 *
 * The correlation identifier comes from a header your infrastructure already sets — an
 * ingress, a load balancer or a tracer. Because that header is client-controlled, a value
 * too long to store is dropped rather than truncated into something that correlates with
 * nothing.
 */
#[AsEventListener(event: KernelEvents::REQUEST)]
final readonly class RequestContextListener
{
    public const string DEFAULT_REQUEST_ID_HEADER = 'X-Request-Id';

    private const int MAX_REQUEST_ID_LENGTH = 64;

    public function __construct(
        private RequestContext $requestContext,
        private string $requestIdHeader = self::DEFAULT_REQUEST_ID_HEADER,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        $this->requestContext->setClientIp($request->getClientIp());
        $this->requestContext->setRequestId($this->storableCorrelationId($request));
    }

    private function storableCorrelationId(Request $request): ?string
    {
        $header = $request->headers->get($this->requestIdHeader);

        if (null === $header) {
            return null;
        }

        $correlationId = trim($header);

        if ('' === $correlationId || \strlen($correlationId) > self::MAX_REQUEST_ID_LENGTH) {
            return null;
        }

        return $correlationId;
    }
}
