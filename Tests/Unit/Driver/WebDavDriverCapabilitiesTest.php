<?php

declare(strict_types=1);

namespace RPflamm\FalWebDavDriver\Tests\Unit\Driver;

use PHPUnit\Framework\Attributes\Test;
use RPflamm\FalWebDavDriver\Driver\WebDavDriver;
use TYPO3\CMS\Core\Resource\Capabilities;
use TYPO3\CMS\Core\Resource\Exception\InvalidConfigurationException;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class WebDavDriverCapabilitiesTest extends UnitTestCase
{
    #[Test]
    public function driverIsBrowsableWritableAndHierarchicalByDefault(): void
    {
        $driver = new WebDavDriver(['baseUri' => 'https://dav.example.com/']);

        self::assertTrue($driver->hasCapability(Capabilities::CAPABILITY_BROWSABLE));
        self::assertTrue($driver->hasCapability(Capabilities::CAPABILITY_WRITABLE));
        self::assertTrue($driver->hasCapability(Capabilities::CAPABILITY_HIERARCHICAL_IDENTIFIERS));
    }

    #[Test]
    public function processConfigurationRemovesPublicCapabilityWithoutPublicBaseUri(): void
    {
        $driver = new WebDavDriver(['baseUri' => 'https://dav.example.com/']);
        $driver->processConfiguration();

        self::assertFalse($driver->hasCapability(Capabilities::CAPABILITY_PUBLIC));
    }

    #[Test]
    public function processConfigurationKeepsPublicCapabilityWithPublicBaseUri(): void
    {
        $driver = new WebDavDriver([
            'baseUri' => 'https://dav.example.com/',
            'publicBaseUri' => 'https://public.example.com/',
        ]);
        $driver->processConfiguration();

        self::assertTrue($driver->hasCapability(Capabilities::CAPABILITY_PUBLIC));
    }

    #[Test]
    public function processConfigurationThrowsWithoutBaseUri(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new WebDavDriver([]))->processConfiguration();
    }

    #[Test]
    public function processConfigurationThrowsForInvalidAuthType(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new WebDavDriver(['baseUri' => 'https://dav.example.com/', 'authType' => 'oauth']))->processConfiguration();
    }

    #[Test]
    public function getRootLevelFolderReturnsSlash(): void
    {
        self::assertSame('/', (new WebDavDriver())->getRootLevelFolder());
    }

    #[Test]
    public function isWithinDetectsNestedIdentifiers(): void
    {
        $driver = new WebDavDriver();

        self::assertTrue($driver->isWithin('/Photos/', '/Photos/foo.jpg'));
        self::assertTrue($driver->isWithin('/Photos/', '/Photos/'));
        self::assertFalse($driver->isWithin('/Photos/', '/Documents/foo.jpg'));
    }

    #[Test]
    public function sanitizeFileNameReplacesUnsafeCharacters(): void
    {
        $driver = new WebDavDriver();

        self::assertSame('my_file_name.jpg', $driver->sanitizeFileName('my/file:name.jpg'));
    }
}
