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
use TYPO3\CMS\Core\Resource\Exception\FileOperationErrorException;
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

        if ($authType === 'bearer' && (!isset($this->configuration['bearerToken']) || $this->configuration['bearerToken'] === '')) {
            throw new InvalidConfigurationException(
                'Configuration must contain a bearerToken when authType is "bearer".',
                1756200049
            );
        }

        if (!isset($this->configuration['publicBaseUri']) || $this->configuration['publicBaseUri'] === '') {
            $this->capabilities->removeCapability(Capabilities::CAPABILITY_PUBLIC);
        }
    }

    public function initialize(): void
    {
        // Core calls initialize() unconditionally, even when processConfiguration()
        // above has thrown InvalidConfigurationException (ResourceStorage catches that
        // exception and merely marks the storage offline). $this->configuration might
        // therefore be missing "baseUri" here; fall back to a safe, non-throwing value
        // instead of relying on it being present.
        $baseUri = isset($this->configuration['baseUri']) && $this->configuration['baseUri'] !== ''
            ? (string) $this->configuration['baseUri']
            : '/';

        $this->client = (new WebDavClientFactory())->create($this->configuration);
        $this->identifierMapper = new IdentifierMapper(rtrim($baseUri, '/') . '/');
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
        $identifier = '/user_upload/';
        if (!$this->folderExists($identifier)) {
            $identifier = $this->createFolder('user_upload');
        }

        return $identifier;
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
        $parentFolderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($parentFolderIdentifier);
        $newFolderName = trim($newFolderName, '/');

        if (!$recursive) {
            $newFolderName = $this->sanitizeFileName($newFolderName);
            $newIdentifier = $this->canonicalizeAndCheckFolderIdentifier($parentFolderIdentifier . $newFolderName . '/');
            $this->sendRequest('MKCOL', $newIdentifier, WebDavStatusCodeMapper::RESOURCE_FOLDER, 'write');

            return $newIdentifier;
        }

        $segments = array_map(
            $this->sanitizeFileName(...),
            array_filter(explode('/', $newFolderName), static fn(string $segment): bool => $segment !== '')
        );

        $currentIdentifier = $parentFolderIdentifier;
        foreach ($segments as $segment) {
            $currentIdentifier = $this->canonicalizeAndCheckFolderIdentifier($currentIdentifier . $segment . '/');
            if (!$this->folderExists($currentIdentifier)) {
                $this->sendRequest('MKCOL', $currentIdentifier, WebDavStatusCodeMapper::RESOURCE_FOLDER, 'write');
            }
        }

        return $currentIdentifier;
    }

    public function renameFolder(string $folderIdentifier, string $newName): array
    {
        $folderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($folderIdentifier);
        $newName = $this->sanitizeFileName($newName);
        $targetIdentifier = $this->canonicalizeAndCheckFolderIdentifier(
            $this->getParentFolderIdentifierOfIdentifier($folderIdentifier) . $newName . '/'
        );

        $affectedEntries = $this->collectEntries($folderIdentifier, true);
        $this->transferResource('MOVE', $folderIdentifier, $targetIdentifier, WebDavStatusCodeMapper::RESOURCE_FOLDER);
        $this->cacheService->invalidate($folderIdentifier);

        return $this->buildIdentifierMap($folderIdentifier, $targetIdentifier, $affectedEntries);
    }

    public function deleteFolder(string $folderIdentifier, bool $deleteRecursively = false): bool
    {
        $folderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($folderIdentifier);

        if (!$deleteRecursively && !$this->isFolderEmpty($folderIdentifier)) {
            throw new FileOperationErrorException(
                sprintf('Folder "%s" is not empty.', $folderIdentifier),
                1756200044
            );
        }

        $this->sendRequest('DELETE', $folderIdentifier, WebDavStatusCodeMapper::RESOURCE_FOLDER, 'write');
        $this->cacheService->invalidate($folderIdentifier);

        return true;
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
        $targetFolderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($targetFolderIdentifier);
        $fileName = $this->sanitizeFileName($newFileName !== '' ? $newFileName : PathUtility::basename($localFilePath));
        $targetIdentifier = $this->canonicalizeAndCheckFileIdentifier($targetFolderIdentifier . $fileName);

        $this->uploadFile($targetIdentifier, (string) file_get_contents($localFilePath));

        if ($removeOriginal) {
            @unlink($localFilePath);
        }

        return $targetIdentifier;
    }

    public function createFile(string $fileName, string $parentFolderIdentifier): string
    {
        $parentFolderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($parentFolderIdentifier);
        $fileName = $this->sanitizeFileName(ltrim($fileName, '/'));
        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($parentFolderIdentifier . $fileName);

        $this->uploadFile($fileIdentifier, '');

        return $fileIdentifier;
    }

    public function copyFileWithinStorage(string $fileIdentifier, string $targetFolderIdentifier, string $fileName): string
    {
        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);
        $targetIdentifier = $this->canonicalizeAndCheckFileIdentifier(
            $this->canonicalizeAndCheckFolderIdentifier($targetFolderIdentifier) . $fileName
        );

        $this->transferResource('COPY', $fileIdentifier, $targetIdentifier, WebDavStatusCodeMapper::RESOURCE_FILE);

        return $targetIdentifier;
    }

    public function renameFile(string $fileIdentifier, string $newName): string
    {
        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);
        $newName = $this->sanitizeFileName($newName);
        $targetIdentifier = $this->canonicalizeAndCheckFileIdentifier(
            $this->getParentFolderIdentifierOfIdentifier($fileIdentifier) . $newName
        );

        $this->transferResource('MOVE', $fileIdentifier, $targetIdentifier, WebDavStatusCodeMapper::RESOURCE_FILE);
        $this->cacheService->invalidate($fileIdentifier);

        return $targetIdentifier;
    }

    public function replaceFile(string $fileIdentifier, string $localFilePath): bool
    {
        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);
        $this->uploadFile($fileIdentifier, (string) file_get_contents($localFilePath));
        $this->cacheService->invalidate($fileIdentifier);

        return true;
    }

    public function deleteFile(string $fileIdentifier): bool
    {
        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);
        $this->sendRequest('DELETE', $fileIdentifier, WebDavStatusCodeMapper::RESOURCE_FILE, 'write');
        $this->cacheService->invalidate($fileIdentifier);

        return true;
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
        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);
        $targetIdentifier = $this->canonicalizeAndCheckFileIdentifier(
            $this->canonicalizeAndCheckFolderIdentifier($targetFolderIdentifier) . $newFileName
        );

        $this->transferResource('MOVE', $fileIdentifier, $targetIdentifier, WebDavStatusCodeMapper::RESOURCE_FILE);
        $this->cacheService->invalidate($fileIdentifier);

        return $targetIdentifier;
    }

    public function moveFolderWithinStorage(string $sourceFolderIdentifier, string $targetFolderIdentifier, string $newFolderName): array
    {
        $sourceFolderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($sourceFolderIdentifier);
        $targetIdentifier = $this->canonicalizeAndCheckFolderIdentifier(
            $this->canonicalizeAndCheckFolderIdentifier($targetFolderIdentifier) . $newFolderName . '/'
        );

        $affectedEntries = $this->collectEntries($sourceFolderIdentifier, true);
        $this->transferResource('MOVE', $sourceFolderIdentifier, $targetIdentifier, WebDavStatusCodeMapper::RESOURCE_FOLDER);
        $this->cacheService->invalidate($sourceFolderIdentifier);

        return $this->buildIdentifierMap($sourceFolderIdentifier, $targetIdentifier, $affectedEntries);
    }

    public function copyFolderWithinStorage(string $sourceFolderIdentifier, string $targetFolderIdentifier, string $newFolderName): bool
    {
        $sourceFolderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($sourceFolderIdentifier);
        $targetIdentifier = $this->canonicalizeAndCheckFolderIdentifier(
            $this->canonicalizeAndCheckFolderIdentifier($targetFolderIdentifier) . $newFolderName . '/'
        );

        $this->transferResource('COPY', $sourceFolderIdentifier, $targetIdentifier, WebDavStatusCodeMapper::RESOURCE_FOLDER, ['Depth' => 'infinity']);

        return true;
    }

    public function getFileContents(string $fileIdentifier): string
    {
        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);

        return (string) file_get_contents($this->resolveLocalCopy($fileIdentifier));
    }

    public function setFileContents(string $fileIdentifier, string $contents): int
    {
        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);
        $this->uploadFile($fileIdentifier, $contents);
        $this->cacheService->invalidate($fileIdentifier);

        return strlen($contents);
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
            $properties = $this->propFind($identifier, 0, $resourceType);
        } catch (FileDoesNotExistException|FolderDoesNotExistException) {
            return false;
        }

        // A WebDAV PROPFIND can succeed for a path that resolves to a resource of the
        // *other* type (e.g. some servers answer a PROPFIND for "/Photos" - without the
        // trailing slash a folder identifier is canonicalized to for file existence checks -
        // with the same 207 response as for "/Photos/"). Without this check, fileExists()
        // would incorrectly report true for an existing folder, and TYPO3 core would then
        // try (and fail) to resolve it as a sys_file record instead of a folder.
        $isCollection = $this->responseTranslator->translate($properties)['isCollection'];

        return $resourceType === WebDavStatusCodeMapper::RESOURCE_FOLDER ? $isCollection : !$isCollection;
    }

    private function uploadFile(string $fileIdentifier, string $contents): void
    {
        $davPath = $this->identifierMapper->toWebDavPath($fileIdentifier);

        try {
            $response = $this->client->request('PUT', $davPath, $contents);
        } catch (ClientException $e) {
            throw new WebDavConnectionException(
                sprintf('Could not upload "%s" to the WebDAV server: %s', $fileIdentifier, $e->getMessage()),
                1756200045,
                $e
            );
        }

        if ((int) $response['statusCode'] >= 400) {
            throw $this->statusCodeMapper->map((int) $response['statusCode'], $fileIdentifier, WebDavStatusCodeMapper::RESOURCE_FILE, 'write');
        }
    }

    private function sendRequest(string $method, string $identifier, string $resourceType, string $access): void
    {
        $davPath = $this->identifierMapper->toWebDavPath($identifier);

        try {
            $response = $this->client->request($method, $davPath);
        } catch (ClientException $e) {
            throw new WebDavConnectionException(
                sprintf('Could not %s "%s" on the WebDAV server: %s', $method, $identifier, $e->getMessage()),
                1756200046,
                $e
            );
        }

        if ((int) $response['statusCode'] >= 400) {
            throw $this->statusCodeMapper->map((int) $response['statusCode'], $identifier, $resourceType, $access);
        }
    }

    /**
     * @param array<string, string> $extraHeaders
     */
    private function transferResource(string $method, string $sourceIdentifier, string $targetIdentifier, string $resourceType, array $extraHeaders = []): void
    {
        $sourcePath = $this->identifierMapper->toWebDavPath($sourceIdentifier);
        $targetUrl = rtrim((string) $this->configuration['baseUri'], '/') . '/' . $this->identifierMapper->toWebDavPath($targetIdentifier);
        $headers = $extraHeaders + ['Destination' => $targetUrl, 'Overwrite' => 'F'];

        try {
            $response = $this->client->request($method, $sourcePath, null, $headers);
        } catch (ClientException $e) {
            throw new WebDavConnectionException(
                sprintf('Could not %s "%s" to "%s": %s', $method, $sourceIdentifier, $targetIdentifier, $e->getMessage()),
                1756200047,
                $e
            );
        }

        if ((int) $response['statusCode'] >= 400) {
            throw $this->statusCodeMapper->map((int) $response['statusCode'], $sourceIdentifier, $resourceType, 'write');
        }
    }

    /**
     * @param array<string, array{size: int, mtime: int, ctime: int, mimetype: string, isCollection: bool, etag: string|null, name: string}> $affectedEntries
     *
     * @return array<non-empty-string, non-empty-string>
     */
    private function buildIdentifierMap(string $sourceIdentifier, string $targetIdentifier, array $affectedEntries): array
    {
        $map = [$sourceIdentifier => $targetIdentifier];
        foreach (array_keys($affectedEntries) as $oldIdentifier) {
            $map[$oldIdentifier] = $targetIdentifier . substr($oldIdentifier, strlen($sourceIdentifier));
        }

        return $map;
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
