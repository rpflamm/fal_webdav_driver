<?php

declare(strict_types=1);

namespace RPflamm\FalWebDavDriver\Tests\Unit\Client;

use PHPUnit\Framework\Attributes\Test;
use RPflamm\FalWebDavDriver\Client\WebDavStatusCodeMapper;
use RPflamm\FalWebDavDriver\Exception\UnexpectedWebDavResponseException;
use TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException;
use TYPO3\CMS\Core\Resource\Exception\FileOperationErrorException;
use TYPO3\CMS\Core\Resource\Exception\FolderDoesNotExistException;
use TYPO3\CMS\Core\Resource\Exception\InsufficientFileReadPermissionsException;
use TYPO3\CMS\Core\Resource\Exception\InsufficientFileWritePermissionsException;
use TYPO3\CMS\Core\Resource\Exception\InsufficientFolderReadPermissionsException;
use TYPO3\CMS\Core\Resource\Exception\InsufficientFolderWritePermissionsException;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class WebDavStatusCodeMapperTest extends UnitTestCase
{
    #[Test]
    public function mapsNotFoundToDoesNotExistExceptions(): void
    {
        $mapper = new WebDavStatusCodeMapper();

        self::assertInstanceOf(
            FileDoesNotExistException::class,
            $mapper->map(404, '/foo.jpg', WebDavStatusCodeMapper::RESOURCE_FILE, WebDavStatusCodeMapper::ACCESS_READ)
        );
        self::assertInstanceOf(
            FolderDoesNotExistException::class,
            $mapper->map(404, '/foo/', WebDavStatusCodeMapper::RESOURCE_FOLDER, WebDavStatusCodeMapper::ACCESS_READ)
        );
    }

    #[Test]
    public function mapsPermissionErrorsDependingOnResourceAndAccess(): void
    {
        $mapper = new WebDavStatusCodeMapper();

        self::assertInstanceOf(
            InsufficientFileReadPermissionsException::class,
            $mapper->map(403, '/foo.jpg', WebDavStatusCodeMapper::RESOURCE_FILE, WebDavStatusCodeMapper::ACCESS_READ)
        );
        self::assertInstanceOf(
            InsufficientFileWritePermissionsException::class,
            $mapper->map(401, '/foo.jpg', WebDavStatusCodeMapper::RESOURCE_FILE, WebDavStatusCodeMapper::ACCESS_WRITE)
        );
        self::assertInstanceOf(
            InsufficientFolderReadPermissionsException::class,
            $mapper->map(403, '/foo/', WebDavStatusCodeMapper::RESOURCE_FOLDER, WebDavStatusCodeMapper::ACCESS_READ)
        );
        self::assertInstanceOf(
            InsufficientFolderWritePermissionsException::class,
            $mapper->map(401, '/foo/', WebDavStatusCodeMapper::RESOURCE_FOLDER, WebDavStatusCodeMapper::ACCESS_WRITE)
        );
    }

    #[Test]
    public function mapsConflictAndLockedStatusesToFileOperationErrorException(): void
    {
        $mapper = new WebDavStatusCodeMapper();

        foreach ([409, 412, 423, 507] as $statusCode) {
            self::assertInstanceOf(
                FileOperationErrorException::class,
                $mapper->map($statusCode, '/foo.jpg', WebDavStatusCodeMapper::RESOURCE_FILE, WebDavStatusCodeMapper::ACCESS_WRITE)
            );
        }
    }

    #[Test]
    public function mapsUnknownStatusCodesToUnexpectedWebDavResponseException(): void
    {
        $mapper = new WebDavStatusCodeMapper();

        self::assertInstanceOf(
            UnexpectedWebDavResponseException::class,
            $mapper->map(500, '/foo.jpg', WebDavStatusCodeMapper::RESOURCE_FILE, WebDavStatusCodeMapper::ACCESS_READ)
        );
    }
}
