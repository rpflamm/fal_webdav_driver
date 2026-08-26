<?php

declare(strict_types=1);

namespace RPflamm\FalWebDavDriver\Driver;

use RPflamm\FalWebDavDriver\Cache\LocalFileCacheService;
use RPflamm\FalWebDavDriver\Client\ResponseTranslator;
use RPflamm\FalWebDavDriver\Client\WebDavClientFactory;
use RPflamm\FalWebDavDriver\Client\WebDavStatusCodeMapper;
use RPflamm\FalWebDavDriver\Exception\WebDavConnectionException;
use RPflamm\FalWebDavDriver\Identifier\IdentifierMapper;
use Sabre\DAV\Client;
use Sabre\HTTP\ClientException;
use Sabre\HTTP\ClientHttpException;
use TYPO3\CMS\Core\Resource\Capabilities;
use TYPO3\CMS\Core\Resource\Driver\AbstractHierarchicalFilesystemDriver;
use TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException;
use TYPO3\CMS\Core\Resource\Exception\FolderDoesNotExistException;
use TYPO3\CMS\Core\Resource\Exception\InvalidConfigurationException;
use TYPO3\CMS\Core\Resource\Exception\InvalidFileNameException;
use TYPO3\CMS\Core\Utility\PathUtility;

final class WebDavDriver extends AbstractHierarchicalFilesystemDriver
{
    private const PROPFIND_PROPERTIES = [
        '{DAV:}resourcetype',
        '{DAV:}getcontentlength',
        '{DAV:}getlastmodified',
        '{DAV:}creationdate',
        '{DAV:}getcontenttype',
        '{DAV:}getetag',
    ];

    private Client $client;
    private IdentifierMapper $identifierMapper;
    private readonly ResponseTranslator $responseTranslator;
    private readonly WebDavStatusCodeMapper $statusCodeMapper;
    private LocalFileCacheService $cacheService;

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
        $this->supportedHashAlgorithms = ['sha1'];
        $this->responseTranslator = new ResponseTranslator();
        $this->statusCodeMapper = new WebDavStatusCodeMapper();
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

    public function initialize(): void
    {
        $this->client = (new WebDavClientFactory())->create($this->configuration);
        $this->identifierMapper = new IdentifierMapper(
            rtrim((string) $this->configuration['baseUri'], '/') . '/'
        );
        $this->cacheService = new LocalFileCacheService(
            (int) $this->storageUid,
            (int) ($this->configuration['cacheLifetime'] ?? 3600)
        );
    }

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
            throw new InvalidFileNameException(
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
        if (!$this->hasCapability(Capabilities::CAPABILITY_PUBLIC)) {
            return null;
        }

        $identifier = $this->canonicalizeAndCheckFileIdentifier($identifier);
        $publicBaseUri = rtrim(
            (string) ($this->configuration['publicBaseUri'] ?? $this->configuration['baseUri']),
            '/'
        ) . '/';

        return $publicBaseUri . $this->identifierMapper->toWebDavPath($identifier);
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
        return $this->resourceExists($this->canonicalizeAndCheckFileIdentifier($fileIdentifier), WebDavStatusCodeMapper::RESOURCE_FILE);
    }

    public function folderExists(string $folderIdentifier): bool
    {
        return $this->resourceExists($this->canonicalizeAndCheckFolderIdentifier($folderIdentifier), WebDavStatusCodeMapper::RESOURCE_FOLDER);
    }

    public function isFolderEmpty(string $folderIdentifier): bool
    {
        return $this->listFolder($folderIdentifier) === [];
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
        if (!in_array($hashAlgorithm, $this->supportedHashAlgorithms, true)) {
            throw new \InvalidArgumentException(
                sprintf('Hash algorithm "%s" is not supported.', $hashAlgorithm),
                1756200036
            );
        }

        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);
        $localFilePath = $this->resolveLocalCopy($fileIdentifier);

        return (string) hash_file($hashAlgorithm, $localFilePath);
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
        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);

        return (string) file_get_contents($this->resolveLocalCopy($fileIdentifier));
    }

    public function setFileContents(string $fileIdentifier, string $contents): int
    {
        throw new \RuntimeException('Not implemented yet.', 1756200022);
    }

    public function fileExistsInFolder(string $fileName, string $folderIdentifier): bool
    {
        return $this->fileExists($this->canonicalizeAndCheckFolderIdentifier($folderIdentifier) . $fileName);
    }

    public function folderExistsInFolder(string $folderName, string $folderIdentifier): bool
    {
        return $this->folderExists($this->canonicalizeAndCheckFolderIdentifier($folderIdentifier) . $folderName . '/');
    }

    public function getFileForLocalProcessing(string $fileIdentifier, bool $writable = true): string
    {
        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);

        if (!$writable) {
            return $this->resolveLocalCopy($fileIdentifier);
        }

        $temporaryPath = $this->getTemporaryPathForFile($fileIdentifier);
        file_put_contents($temporaryPath, $this->fetchRemoteContents($fileIdentifier));
        $this->cacheService->invalidate($fileIdentifier);

        return $temporaryPath;
    }

    public function getPermissions(string $identifier): array
    {
        return [
            'r' => true,
            'w' => $this->hasCapability(Capabilities::CAPABILITY_WRITABLE),
        ];
    }

    public function dumpFileContents(string $identifier): void
    {
        echo $this->getFileContents($identifier);
    }

    public function getFileInfoByIdentifier(string $fileIdentifier, array $propertiesToExtract = []): array
    {
        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);
        $translated = $this->responseTranslator->translate(
            $this->propFind($fileIdentifier, 0, WebDavStatusCodeMapper::RESOURCE_FILE)
        );

        return $this->buildFileInfo($fileIdentifier, $translated, $propertiesToExtract);
    }

    public function getFolderInfoByIdentifier(string $folderIdentifier): array
    {
        $folderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($folderIdentifier);
        $translated = $this->responseTranslator->translate(
            $this->propFind($folderIdentifier, 0, WebDavStatusCodeMapper::RESOURCE_FOLDER)
        );

        return [
            'identifier' => $folderIdentifier,
            'name' => $folderIdentifier === '/' ? '' : PathUtility::basename(rtrim($folderIdentifier, '/')),
            'mtime' => $translated['mtime'],
            'ctime' => $translated['ctime'],
            'storage' => $this->storageUid,
        ];
    }

    public function getFileInFolder(string $fileName, string $folderIdentifier): string
    {
        return $this->canonicalizeAndCheckFileIdentifier($this->canonicalizeAndCheckFolderIdentifier($folderIdentifier) . $fileName);
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
        $identifiers = $this->getSortedFilteredIdentifiers($folderIdentifier, true, false, $recursive, $filenameFilterCallbacks, $sort, $sortRev);

        return array_values(array_slice($identifiers, $start, $numberOfItems > 0 ? $numberOfItems : null));
    }

    public function getFolderInFolder(string $folderName, string $folderIdentifier): string
    {
        return $this->canonicalizeAndCheckFolderIdentifier($folderIdentifier . '/' . $folderName);
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
        $identifiers = $this->getSortedFilteredIdentifiers($folderIdentifier, false, true, $recursive, $folderNameFilterCallbacks, $sort, $sortRev);

        return array_slice($identifiers, $start, $numberOfItems > 0 ? $numberOfItems : null, true);
    }

    public function countFilesInFolder(string $folderIdentifier, bool $recursive = false, array $filenameFilterCallbacks = []): int
    {
        return count($this->getFilesInFolder($folderIdentifier, 0, 0, $recursive, $filenameFilterCallbacks));
    }

    public function countFoldersInFolder(string $folderIdentifier, bool $recursive = false, array $folderNameFilterCallbacks = []): int
    {
        return count($this->getFoldersInFolder($folderIdentifier, 0, 0, $recursive, $folderNameFilterCallbacks));
    }

    private function resourceExists(string $identifier, string $resourceType): bool
    {
        try {
            $this->propFind($identifier, 0, $resourceType);
            return true;
        } catch (FileDoesNotExistException|FolderDoesNotExistException) {
            return false;
        }
    }

    private function resolveLocalCopy(string $fileIdentifier): string
    {
        return $this->cacheService->resolve(
            $fileIdentifier,
            fn(): ?string => $this->fetchRemoteEtag($fileIdentifier),
            fn(): string => $this->fetchRemoteContents($fileIdentifier)
        );
    }

    private function fetchRemoteEtag(string $fileIdentifier): ?string
    {
        $properties = $this->propFind($fileIdentifier, 0, WebDavStatusCodeMapper::RESOURCE_FILE);

        return $this->responseTranslator->translate($properties)['etag'];
    }

    private function fetchRemoteContents(string $fileIdentifier): string
    {
        $davPath = $this->identifierMapper->toWebDavPath($fileIdentifier);

        try {
            $response = $this->client->request('GET', $davPath);
        } catch (ClientException $e) {
            throw new WebDavConnectionException(
                sprintf('Could not download "%s" from the WebDAV server: %s', $fileIdentifier, $e->getMessage()),
                1756200037,
                $e
            );
        }

        if ((int) $response['statusCode'] >= 400) {
            throw $this->statusCodeMapper->map((int) $response['statusCode'], $fileIdentifier, WebDavStatusCodeMapper::RESOURCE_FILE, 'read');
        }

        return (string) $response['body'];
    }

    /**
     * @param non-empty-string $identifier
     * @param 0|1 $depth
     *
     * @return array<string, mixed>
     */
    private function propFind(string $identifier, int $depth, string $resourceType): array
    {
        $davPath = $this->identifierMapper->toWebDavPath($identifier);

        try {
            return $this->client->propFind($davPath, self::PROPFIND_PROPERTIES, $depth);
        } catch (ClientHttpException $e) {
            throw $this->statusCodeMapper->map($e->getHttpStatus(), $identifier, $resourceType, 'read');
        } catch (ClientException $e) {
            throw new WebDavConnectionException(
                sprintf('Could not connect to the WebDAV server while accessing "%s": %s', $identifier, $e->getMessage()),
                1756200038,
                $e
            );
        }
    }

    /**
     * @return array<string, array{size: int, mtime: int, ctime: int, mimetype: string, isCollection: bool, etag: string|null, name: string}>
     */
    private function listFolder(string $folderIdentifier): array
    {
        $folderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($folderIdentifier);
        $result = $this->propFind($folderIdentifier, 1, WebDavStatusCodeMapper::RESOURCE_FOLDER);

        $entries = [];
        foreach ($result as $href => $properties) {
            $translated = $this->responseTranslator->translate($properties);
            $identifier = $this->identifierMapper->fromHref((string) $href);
            $identifier = $translated['isCollection']
                ? $this->canonicalizeAndCheckFolderIdentifier($identifier)
                : $this->canonicalizeAndCheckFileIdentifier($identifier);

            if ($identifier === $folderIdentifier) {
                continue;
            }

            $entries[$identifier] = $translated + ['name' => PathUtility::basename(rtrim($identifier, '/'))];
        }

        return $entries;
    }

    /**
     * @return array<string, array{size: int, mtime: int, ctime: int, mimetype: string, isCollection: bool, etag: string|null, name: string}>
     */
    private function collectEntries(string $folderIdentifier, bool $recursive): array
    {
        $entries = $this->listFolder($folderIdentifier);

        if ($recursive) {
            foreach ($entries as $identifier => $entry) {
                if ($entry['isCollection']) {
                    $entries += $this->collectEntries($identifier, true);
                }
            }
        }

        return $entries;
    }

    /**
     * @param list<callable> $filterMethods
     *
     * @return array<string, string>
     */
    private function getSortedFilteredIdentifiers(
        string $folderIdentifier,
        bool $includeFiles,
        bool $includeDirs,
        bool $recursive,
        array $filterMethods,
        string $sort,
        bool $sortRev
    ): array {
        $entries = $this->collectEntries($folderIdentifier, $recursive);
        $entries = array_filter(
            $entries,
            static fn(array $entry): bool => ($includeFiles && !$entry['isCollection']) || ($includeDirs && $entry['isCollection'])
        );
        $entries = $this->sortEntries($entries, $sort, $sortRev);

        $identifiers = [];
        foreach ($entries as $identifier => $entry) {
            if (!$this->applyFilterMethodsToDirectoryItem($filterMethods, $entry['name'], $identifier)) {
                continue;
            }
            $identifiers[$identifier] = $identifier;
        }

        return $identifiers;
    }

    /**
     * @param array<string, array{size: int, mtime: int, ctime: int, mimetype: string, isCollection: bool, etag: string|null, name: string}> $entries
     *
     * @return array<string, array{size: int, mtime: int, ctime: int, mimetype: string, isCollection: bool, etag: string|null, name: string}>
     */
    private function sortEntries(array $entries, string $sort, bool $sortRev): array
    {
        if ($sort !== '') {
            uasort($entries, static function (array $a, array $b) use ($sort): int {
                return match ($sort) {
                    'size' => $a['size'] <=> $b['size'],
                    'tstamp' => $a['mtime'] <=> $b['mtime'],
                    'fileext' => strcasecmp(
                        (string) PathUtility::pathinfo($a['name'], PATHINFO_EXTENSION),
                        (string) PathUtility::pathinfo($b['name'], PATHINFO_EXTENSION)
                    ),
                    default => strnatcasecmp($a['name'], $b['name']),
                };
            });
        }

        return $sortRev ? array_reverse($entries, true) : $entries;
    }

    /**
     * @param list<callable> $filterMethods
     */
    private function applyFilterMethodsToDirectoryItem(array $filterMethods, string $itemName, string $itemIdentifier): bool
    {
        foreach ($filterMethods as $filter) {
            if (is_callable($filter)) {
                $result = $filter($itemName, $itemIdentifier, $this->getParentFolderIdentifierOfIdentifier($itemIdentifier), [], $this);
                if ($result === -1 || $result === false) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @param array{size: int, mtime: int, ctime: int, mimetype: string, isCollection: bool, etag: string|null} $translated
     * @param list<string> $propertiesToExtract
     *
     * @return array<string, mixed>
     */
    private function buildFileInfo(string $identifier, array $translated, array $propertiesToExtract): array
    {
        if ($propertiesToExtract === []) {
            $propertiesToExtract = [
                'size', 'atime', 'mtime', 'ctime', 'mimetype', 'name', 'extension',
                'identifier', 'identifier_hash', 'storage', 'folder_hash',
            ];
        }

        $info = [];
        foreach ($propertiesToExtract as $property) {
            $info[$property] = match ($property) {
                'size' => $translated['size'],
                'atime', 'mtime' => $translated['mtime'],
                'ctime' => $translated['ctime'],
                'mimetype' => $translated['mimetype'],
                'name' => PathUtility::basename($identifier),
                'extension' => PathUtility::pathinfo($identifier, PATHINFO_EXTENSION),
                'identifier' => $identifier,
                'storage' => $this->storageUid,
                'identifier_hash' => $this->hashIdentifier($identifier),
                'folder_hash' => $this->hashIdentifier($this->getParentFolderIdentifierOfIdentifier($identifier)),
                default => throw new \InvalidArgumentException(
                    sprintf('The information "%s" is not available.', $property),
                    1756200039
                ),
            };
        }

        return $info;
    }
}
