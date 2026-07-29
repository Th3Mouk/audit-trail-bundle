<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Bridge\ApiPlatform;

use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\HttpFoundation\Response;
use Th3Mouk\AuditTrail\Tests\Bridge\ApiPlatform\Case\AuditFeedTestCase;
use Th3Mouk\AuditTrail\Tests\Fixtures\Kernel\ApiPlatformKernel;

/**
 * The three ways to say who may read the trail, and the refusal to guess.
 *
 * A permission name belongs to the application, never to this bundle — so the interesting
 * assertions here are that each form reaches Symfony's authorization checker unchanged, and that
 * declaring none of them stops the container from compiling instead of publishing the feed.
 */
final class AuditFeedAccessModesTest extends AuditFeedTestCase
{
    /**
     * The mapping form, for permission systems anchored on a resource: the attribute and the subject
     * arrive at `is_granted($attribute, $subject)` as two arguments, which is what a voter deciding
     * "may this principal view audit-logs?" needs.
     */
    public function testAGrantWithASubjectReachesTheVoterAsTwoArguments(): void
    {
        $this->bootWith(self::accessConfig([
            'grants' => [['attribute' => ApiPlatformKernel::SUBJECT_ATTRIBUTE, 'subject' => ApiPlatformKernel::SUBJECT]],
        ]));
        $identifiers = $this->seedEntries(1);

        $granted = $this->get(self::COLLECTION, ApiPlatformKernel::subjectReaderCredentials());
        self::assertSame(Response::HTTP_OK, $granted->getStatusCode());
        self::assertSame($identifiers, $this->idsOf($this->payloadOf($granted)));

        $refused = $this->get(self::COLLECTION, ApiPlatformKernel::strangerCredentials());
        self::assertSame(Response::HTTP_FORBIDDEN, $refused->getStatusCode());
    }

    public function testAnyOneOfSeveralGrantsIsEnough(): void
    {
        $this->bootWith(self::accessConfig([
            'grants' => ['ROLE_NOBODY_HAS_THIS', ApiPlatformKernel::READER_ATTRIBUTE],
        ]));
        $this->seedEntries(1);

        $response = $this->get(self::COLLECTION, ApiPlatformKernel::readerCredentials());

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testARawExpressionIsPassedThroughUntouched(): void
    {
        $this->bootWith(self::accessConfig([
            'expression' => \sprintf("is_granted('%s') and user.getUserIdentifier() != ''", ApiPlatformKernel::READER_ATTRIBUTE),
        ]));
        $this->seedEntries(1);

        self::assertSame(
            Response::HTTP_OK,
            $this->get(self::COLLECTION, ApiPlatformKernel::readerCredentials())->getStatusCode(),
        );
        self::assertSame(
            Response::HTTP_FORBIDDEN,
            $this->get(self::COLLECTION, ApiPlatformKernel::strangerCredentials())->getStatusCode(),
        );
    }

    public function testEnablingTheFeedWithoutDeclaringAccessRefusesToCompile(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/exactly one of "grants", "expression" or "public"/');

        $this->bootWith(self::accessConfig([]));
    }

    public function testDeclaringTwoAccessModesRefusesToCompile(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/exactly one of "grants", "expression" or "public"/');

        $this->bootWith(self::accessConfig([
            'grants' => [ApiPlatformKernel::READER_ATTRIBUTE],
            'public' => true,
        ]));
    }

    /**
     * @param array<string, mixed> $access
     *
     * @return array<string, mixed>
     */
    private static function accessConfig(array $access): array
    {
        // The kernel's own grants are cleared first: configuration is merged recursively, so a test
        // choosing another mode has to say that the default one no longer applies.
        return [
            'bridges' => ['api_platform' => ['access' => array_replace(['grants' => []], $access)]],
        ];
    }
}
