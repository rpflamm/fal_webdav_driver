<?php

declare(strict_types=1);

namespace RPflamm\FalWebDavDriver\Driver;

use TYPO3\CMS\Core\Resource\Capabilities;
use TYPO3\CMS\Core\Resource\Driver\AbstractHierarchicalFilesystemDriver;
use TYPO3\CMS\Core\Resource\Exception\InvalidConfigurationException;

final class WebDavDriver extends AbstractHierarchicalFilesystemDriver
{
    /**
     * @param array<string, mixed> $configuration
     */
    public function __construct(array $configuration = [])
    {
        parent::__construct($configuration);
        $this->capabilities = new Capabilities(
            Capabilities::CAPABILITY_BROWSABLE
            | Capabilities::CAPABILITY_PUBLIC
            | Capabilities::CAPABILITY_WRITABLE
            | Capabilities::CAPABILITY_HIERARCHICAL_IDENTIFIERS
        );
    }

    public function mergeConfigurationCapabilities(Capabilities $capabilities): Capabilities
    {
        $this->capabilities->and($capabilities);
        return $this->capabilities;
    }

    public function processConfiguration(): void
    {
        if (!isset($this->configuration['baseUri']) || $this->configuration['baseUri'] === '') {
            throw new InvalidConfigurationException(
                'Configuration must contain a WebDAV base URI.',
                1756200001
            );
        }

        $authType = $this->configuration['authType'] ?? 'basic';
        if (!in_array($authType, ['basic', 'bearer'], true)) {
            throw new InvalidConfigurationException(
                'Configuration "authType" must be either "basic" or "bearer".',
                1756200002
            );
        }

        if (!isset($this->configuration['publicBaseUri']) || $this->configuration['publicBaseUri'] === '') {
            $this->capabilities->removeCapability(Capabilities::CAPABILITY_PUBLIC);
        }
    }

    public function initialize(): void {}

    public function getRootLevelFolder(): string
    {
        return '/';
    }

    public function getDefaultFolder(): string
    {
        throw new \RuntimeException('Not implemented yet.', 1756200099);
    }

    public function sanitizeFileName(string $fileName): string
    {
        $normalizedFileName = \Normalizer::normalize($fileName);
        if ($normalizedFileName !== false) {
            $fileName = $normalizedFileName;
        }
        $cleanFileName = (string) preg_replace('/[\\x00-\\x2C\\/\\x3A-\\x3F\\x5B-\\x60\\x7B-\\xBF]/u', '_', trim($fileName));
        if (!$this->isCaseSensitiveFileSystem()) {
            $cleanFileName = mb_strtolower($cleanFileName, 'utf-8');
        }
        $cleanFileName = rtrim($cleanFileName, '.');
        if ($cleanFileName === '') {
            throw new \TYPO3\CMS\Core\Resource\Exception\InvalidFileNameException(
                'File name ' . $fileName . ' is invalid.',
                1756200003
            );
        }
        return $cleanFileName;
    }

    public function isWithin(string $folderIdentifier, string $identifier): bool
    {
        $folderIdentifier = $this->canonicalizeAndCheckFileIdentifier($folderIdentifier);
        $entryIdentifier = $this->canonicalizeAndCheckFileIdentifier($identifier);
        if ($folderIdentifier === $entryIdentifier) {
            return true;
        }
        if ($folderIdentifier !== '/') {
            $folderIdentifier .= '/';
        }
        return str_starts_with($entryIdentifier, $folderIdentifier);
    }

    public function getPublicUrl(string $identifier): ?string
    {
        throw new \RuntimeException('Not implemented yet.', 1756200004);
    }

    public function createFolder(string $newFolderName, string $parentFolderIdentifier = '', bool $recursive = false): string
    {
        throw new \RuntimeException('Not implemented yet.', 1756200005);
    }

    public function renameFolder(string $folderIdentifier, string $newName): array
    {
        throw new \RuntimeException('Not implemented yet.', 1756200006);
    }

    public function deleteFolder(string $folderIdentifier, bool $deleteRecursively = false): bool
    {
        throw new \RuntimeException('Not implemented yet.', 1756200007);
    }

    public function fileExists(string $fileIdentifier): bool
    {
        throw new \RuntimeException('Not implemented yet.', 1756200008);
    }

    public function folderExists(string $folderIdentifier): bool
    {
        throw new \RuntimeException('Not implemented yet.', 1756200009);
    }

    public function isFolderEmpty(string $folderIdentifier): bool
    {
        throw new \RuntimeException('Not implemented yet.', 1756200010);
    }

    public function addFile(string $localFilePath, string $targetFolderIdentifier, string $newFileName = '', bool $removeOriginal = true): string
    {
        throw new \RuntimeException('Not implemented yet.', 1756200011);
    }

    public function createFile(string $fileName, string $parentFolderIdentifier): string
    {
        throw new \RuntimeException('Not implemented yet.', 1756200012);
    }

    public function copyFileWithinStorage(string $fileIdentifier, string $targetFolderIdentifier, string $fileName): string
    {
        throw new \RuntimeException('Not implemented yet.', 1756200013);
    }

    public function renameFile(string $fileIdentifier, string $newName): string
    {
        throw new \RuntimeException('Not implemented yet.', 1756200014);
    }

    public function replaceFile(string $fileIdentifier, string $localFilePath): bool
    {
        throw new \RuntimeException('Not implemented yet.', 1756200015);
    }

    public function deleteFile(string $fileIdentifier): bool
    {
        throw new \RuntimeException('Not implemented yet.', 1756200016);
    }

    public function hash(string $fileIdentifier, string $hashAlgorithm): string
    {
        throw new \RuntimeException('Not implemented yet.', 1756200017);
    }

    public function moveFileWithinStorage(string $fileIdentifier, string $targetFolderIdentifier, string $newFileName): string
    {
        throw new \RuntimeException('Not implemented yet.', 1756200018);
    }

    public function moveFolderWithinStorage(string $sourceFolderIdentifier, string $targetFolderIdentifier, string $newFolderName): array
    {
        throw new \RuntimeException('Not implemented yet.', 1756200019);
    }

    public function copyFolderWithinStorage(string $sourceFolderIdentifier, string $targetFolderIdentifier, string $newFolderName): bool
    {
        throw new \RuntimeException('Not implemented yet.', 1756200020);
    }

    public function getFileContents(string $fileIdentifier): string
    {
        throw new \RuntimeException('Not implemented yet.', 1756200021);
    }

    public function setFileContents(string $fileIdentifier, string $contents): int
    {
        throw new \RuntimeException('Not implemented yet.', 1756200022);
    }

    public function fileExistsInFolder(string $fileName, string $folderIdentifier): bool
    {
        throw new \RuntimeException('Not implemented yet.', 1756200023);
    }

    public function folderExistsInFolder(string $folderName, string $folderIdentifier): bool
    {
        throw new \RuntimeException('Not implemented yet.', 1756200024);
    }

    public function getFileForLocalProcessing(string $fileIdentifier, bool $writable = true): string
    {
        throw new \RuntimeException('Not implemented yet.', 1756200025);
    }

    public function getPermissions(string $identifier): array
    {
        throw new \RuntimeException('Not implemented yet.', 1756200026);
    }

    public function dumpFileContents(string $identifier): void
    {
        throw new \RuntimeException('Not implemented yet.', 1756200027);
    }

    public function getFileInfoByIdentifier(string $fileIdentifier, array $propertiesToExtract = []): array
    {
        throw new \RuntimeException('Not implemented yet.', 1756200028);
    }

    public function getFolderInfoByIdentifier(string $folderIdentifier): array
    {
        throw new \RuntimeException('Not implemented yet.', 1756200029);
    }

    public function getFileInFolder(string $fileName, string $folderIdentifier): string
    {
        throw new \RuntimeException('Not implemented yet.', 1756200030);
    }

    public function getFilesInFolder(
        string $folderIdentifier,
        int $start = 0,
        int $numberOfItems = 0,
        bool $recursive = false,
        array $filenameFilterCallbacks = [],
        string $sort = '',
        bool $sortRev = false
    ): array {
        throw new \RuntimeException('Not implemented yet.', 1756200031);
    }

    public function getFolderInFolder(string $folderName, string $folderIdentifier): string
    {
        throw new \RuntimeException('Not implemented yet.', 1756200032);
    }

    public function getFoldersInFolder(
        string $folderIdentifier,
        int $start = 0,
        int $numberOfItems = 0,
        bool $recursive = false,
        array $folderNameFilterCallbacks = [],
        string $sort = '',
        bool $sortRev = false
    ): array {
        throw new \RuntimeException('Not implemented yet.', 1756200033);
    }

    public function countFilesInFolder(string $folderIdentifier, bool $recursive = false, array $filenameFilterCallbacks = []): int
    {
        throw new \RuntimeException('Not implemented yet.', 1756200034);
    }

    public function countFoldersInFolder(string $folderIdentifier, bool $recursive = false, array $folderNameFilterCallbacks = []): int
    {
        throw new \RuntimeException('Not implemented yet.', 1756200035);
    }
}
