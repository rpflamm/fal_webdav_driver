<?php

declare(strict_types=1);

namespace RPflamm\FalWebDavDriver\Cache;

use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Caches remote WebDAV file contents on local disk so that repeated reads
 * (getFileContents(), getFileForLocalProcessing(), hash()) within the
 * configured lifetime do not trigger a new download. Freshness beyond the
 * TTL is verified via the WebDAV ETag before falling back to a full GET.
 */
final class LocalFileCacheService
{
    public function __construct(
        private readonly int $storageUid,
        private readonly int $cacheLifetime,
    ) {}

    /**
     * @param callable(): (string|null) $fetchRemoteEtag
     * @param callable(): string $fetchRemoteContents
     *
     * @return non-empty-string Path to a local file with the current contents of $identifier
     */
    public function resolve(string $identifier, callable $fetchRemoteEtag, callable $fetchRemoteContents): string
    {
        if ($this->isFresh($identifier)) {
            /** @var non-empty-string $cachedFilePath */
            $cachedFilePath = $this->cacheFilePath($identifier);

            return $cachedFilePath;
        }

        $remoteEtag = $fetchRemoteEtag();
        $cachedFilePath = $this->cacheFilePath($identifier);

        if ($remoteEtag !== null && file_exists($cachedFilePath) && $remoteEtag === $this->getCachedEtag($identifier)) {
            $this->writeMeta($identifier, $remoteEtag);

            /** @var non-empty-string $cachedFilePath */
            return $cachedFilePath;
        }

        GeneralUtility::writeFile($cachedFilePath, $fetchRemoteContents());
        $this->writeMeta($identifier, $remoteEtag);

        /** @var non-empty-string $cachedFilePath */
        return $cachedFilePath;
    }

    public function invalidate(string $identifier): void
    {
        @unlink($this->cacheFilePath($identifier));
        @unlink($this->metaFilePath($identifier));
    }

    private function isFresh(string $identifier): bool
    {
        if (!file_exists($this->cacheFilePath($identifier))) {
            return false;
        }

        $meta = $this->readMeta($identifier);

        return $meta !== null && ($meta['cachedAt'] + $this->cacheLifetime) > time();
    }

    private function getCachedEtag(string $identifier): ?string
    {
        return $this->readMeta($identifier)['etag'] ?? null;
    }

    /**
     * @return array{etag: string|null, cachedAt: int}|null
     */
    private function readMeta(string $identifier): ?array
    {
        $metaPath = $this->metaFilePath($identifier);
        if (!file_exists($metaPath)) {
            return null;
        }

        $meta = json_decode((string) file_get_contents($metaPath), true);
        if (!is_array($meta) || !isset($meta['cachedAt'])) {
            return null;
        }

        return [
            'etag' => is_string($meta['etag'] ?? null) ? $meta['etag'] : null,
            'cachedAt' => (int) $meta['cachedAt'],
        ];
    }

    private function writeMeta(string $identifier, ?string $etag): void
    {
        GeneralUtility::writeFile($this->metaFilePath($identifier), json_encode([
            'etag' => $etag,
            'cachedAt' => time(),
        ], JSON_THROW_ON_ERROR));
    }

    private function cacheFilePath(string $identifier): string
    {
        return $this->cacheDirectory() . sha1($identifier) . '.bin';
    }

    private function metaFilePath(string $identifier): string
    {
        return $this->cacheDirectory() . sha1($identifier) . '.json';
    }

    private function cacheDirectory(): string
    {
        $path = Environment::getVarPath() . '/transient/fal_webdav_driver/' . $this->storageUid . '/';
        if (!is_dir($path)) {
            GeneralUtility::mkdir_deep($path);
        }

        return $path;
    }
}
