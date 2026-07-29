<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;
use Th3Mouk\AuditTrail\Attribute\AuditMasked;
use Th3Mouk\AuditTrail\Attribute\NotAuditable;

/**
 * An embeddable carrying one of each field policy.
 *
 * Doctrine reports a change to an embedded field under a dotted key — `credentials.secret` — which
 * is not a property of the owning class. A resolver that looks the name up literally finds nothing,
 * falls back to the default policy, and writes the secret out in clear. This fixture exists so that
 * failure is a test rather than an incident.
 */
#[ORM\Embeddable]
class Credentials
{
    public function __construct(
        #[ORM\Column(length: 64)]
        private string $username,
        #[AuditMasked]
        #[ORM\Column(length: 255)]
        private string $secret,
        #[NotAuditable]
        #[ORM\Column(length: 64)]
        private string $fingerprint,
    ) {
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getSecret(): string
    {
        return $this->secret;
    }

    public function getFingerprint(): string
    {
        return $this->fingerprint;
    }

    public function rotate(string $secret, string $fingerprint): void
    {
        $this->secret = $secret;
        $this->fingerprint = $fingerprint;
    }

    public function rename(string $username): void
    {
        $this->username = $username;
    }
}
