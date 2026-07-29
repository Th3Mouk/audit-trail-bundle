<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Fixtures\Kernel;

/**
 * The bundle with nothing optional installed around it.
 *
 * FrameworkBundle, DoctrineBundle, AuditTrailBundle. No security, no API Platform, no Gedmo,
 * no fixture that reaches for any of them. That this kernel boots and captures is the
 * strongest claim the test suite makes: the bundle has no hidden coupling, and an
 * application can adopt it without adopting anything else.
 */
final class DoctrineOnlyKernel extends TestKernel
{
}
