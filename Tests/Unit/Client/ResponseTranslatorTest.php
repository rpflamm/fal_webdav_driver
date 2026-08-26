<?php

declare(strict_types=1);

namespace RPflamm\FalWebDavDriver\Tests\Unit\Client;

use PHPUnit\Framework\Attributes\Test;
use RPflamm\FalWebDavDriver\Client\ResponseTranslator;
use Sabre\DAV\Xml\Property\ResourceType;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ResponseTranslatorTest extends UnitTestCase
{
    #[Test]
    public function translateDetectsCollectionsViaResourceType(): void
    {
        $translator = new ResponseTranslator();

        $folder = $translator->translate(['{DAV:}resourcetype' => new ResourceType('{DAV:}collection')]);
        $file = $translator->translate(['{DAV:}resourcetype' => new ResourceType()]);

        self::assertTrue($folder['isCollection']);
        self::assertFalse($file['isCollection']);
    }

    #[Test]
    public function translateExtractsSizeMimetypeAndEtag(): void
    {
        $translator = new ResponseTranslator();

        $result = $translator->translate([
            '{DAV:}getcontentlength' => '1234',
            '{DAV:}getcontenttype' => 'image/jpeg',
            '{DAV:}getetag' => '"abc123"',
        ]);

        self::assertSame(1234, $result['size']);
        self::assertSame('image/jpeg', $result['mimetype']);
        self::assertSame('abc123', $result['etag']);
    }

    #[Test]
    public function translateParsesLastModifiedAndFallsBackToItForCreationDate(): void
    {
        $translator = new ResponseTranslator();

        $result = $translator->translate([
            '{DAV:}getlastmodified' => 'Mon, 12 Jan 2026 10:00:00 GMT',
        ]);

        self::assertSame(strtotime('2026-01-12T10:00:00+00:00'), $result['mtime']);
        self::assertSame($result['mtime'], $result['ctime']);
    }

    #[Test]
    public function translateUsesCreationDateWhenAvailable(): void
    {
        $translator = new ResponseTranslator();

        $result = $translator->translate([
            '{DAV:}getlastmodified' => 'Mon, 12 Jan 2026 10:00:00 GMT',
            '{DAV:}creationdate' => '2025-01-01T00:00:00Z',
        ]);

        self::assertSame(strtotime('2025-01-01T00:00:00Z'), $result['ctime']);
    }

    #[Test]
    public function translateReturnsDefaultsForMissingProperties(): void
    {
        $translator = new ResponseTranslator();

        $result = $translator->translate([]);

        self::assertSame(0, $result['size']);
        self::assertSame(0, $result['mtime']);
        self::assertSame(0, $result['ctime']);
        self::assertSame('', $result['mimetype']);
        self::assertFalse($result['isCollection']);
        self::assertNull($result['etag']);
    }
}
