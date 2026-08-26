<?php

declare(strict_types=1);

namespace RPflamm\FalWebDavDriver\Client;

use RPflamm\FalWebDavDriver\Exception\UnexpectedWebDavResponseException;
use TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException;
use TYPO3\CMS\Core\Resource\Exception\FileOperationErrorException;
use TYPO3\CMS\Core\Resource\Exception\FolderDoesNotExistException;
use TYPO3\CMS\Core\Resource\Exception\InsufficientFileReadPermissionsException;
use TYPO3\CMS\Core\Resource\Exception\InsufficientFileWritePermissionsException;
use TYPO3\CMS\Core\Resource\Exception\InsufficientFolderReadPermissionsException;
use TYPO3\CMS\Core\Resource\Exception\InsufficientFolderWritePermissionsException;

/**
 * Maps a WebDAV HTTP response status code to the matching FAL resource exception.
 */
final class WebDavStatusCodeMapper
{
    public const RESOURCE_FILE = 'file';
    public const RESOURCE_FOLDER = 'folder';

    public const ACCESS_READ = 'read';
    public const ACCESS_WRITE = 'write';

    public function map(int $statusCode, string $identifier, string $resourceType, string $access): \Throwable
    {
        if ($statusCode === 404) {
            return $resourceType === self::RESOURCE_FOLDER
                ? new FolderDoesNotExistException(sprintf('Folder "%s" does not exist.', $identifier), 1756200201)
                : new FileDoesNotExistException(sprintf('File "%s" does not exist.', $identifier), 1756200202);
        }

        if ($statusCode === 401 || $statusCode === 403) {
            return $this->mapPermissionException($identifier, $resourceType, $access);
        }

        if (in_array($statusCode, [409, 412, 423, 507], true)) {
            return new FileOperationErrorException(
                sprintf('WebDAV request for "%s" failed with status %d.', $identifier, $statusCode),
                1756200203
            );
        }

        return new UnexpectedWebDavResponseException(
            sprintf('Unexpected WebDAV response (%d) for "%s".', $statusCode, $identifier),
            1756200204
        );
    }

    private function mapPermissionException(string $identifier, string $resourceType, string $access): \Throwable
    {
        if ($resourceType === self::RESOURCE_FOLDER) {
            return $access === self::ACCESS_WRITE
                ? new InsufficientFolderWritePermissionsException(sprintf('Insufficient permissions to write folder "%s".', $identifier), 1756200205)
                : new InsufficientFolderReadPermissionsException(sprintf('Insufficient permissions to read folder "%s".', $identifier), 1756200206);
        }

        return $access === self::ACCESS_WRITE
            ? new InsufficientFileWritePermissionsException(sprintf('Insufficient permissions to write file "%s".', $identifier), 1756200207)
            : new InsufficientFileReadPermissionsException(sprintf('Insufficient permissions to read file "%s".', $identifier), 1756200208);
    }
}
