<?php

declare(strict_types=1);

namespace RPflamm\FalWebDavDriver\Tests\Unit\Cache;

use PHPUnit\Framework\Attributes\Test;
use RPflamm\FalWebDavDriver\Cache\LocalFileCacheService;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class LocalFileCacheServiceTest extends UnitTestCase
{
    #[Test]
    public function resolveDownloadsContentsOnFirstAccess(): void
    {
        $cache = new LocalFileCacheService(9001, 3600);
        $cache->invalidate('/foo.jpg');

        $downloadCount = 0;
        $path = $cache->resolve(
            '/foo.jpg',
            fn(): string => 'etag-1',
            function () use (&$downloadCount): string {
                $downloadCount++;
                return 'first contents';
            }
        );

        self::assertSame('first contents', file_get_contents($path));
        self::assertSame(1, $downloadCount);

        $cache->invalidate('/foo.jpg');
    }

    #[Test]
    public function resolveServesFromCacheWithoutFetchingEtagWithinTtl(): void
    {
        $cache = new LocalFileCacheService(9002, 3600);
        $cache->invalidate('/foo.jpg');

        $etagFetchCount = 0;
        $fetchEtag = function () use (&$etagFetchCount): string {
            $etagFetchCount++;
            return 'etag-1';
        };
        $fetchContents = fn(): string => 'contents';

        $cache->resolve('/foo.jpg', $fetchEtag, $fetchContents);
        $path = $cache->resolve('/foo.jpg', $fetchEtag, $fetchContents);

        self::assertSame('contents', file_get_contents($path));
        self::assertSame(1, $etagFetchCount);

        $cache->invalidate('/foo.jpg');
    }

    #[Test]
    public function resolveSkipsDownloadWhenEtagIsUnchangedAfterTtlExpiry(): void
    {
        $cache = new LocalFileCacheService(9003, -1);
        $cache->invalidate('/foo.jpg');

        $downloadCount = 0;
        $fetchContents = function () use (&$downloadCount): string {
            $downloadCount++;
            return 'contents';
        };

        $cache->resolve('/foo.jpg', fn(): string => 'stable-etag', $fetchContents);
        $path = $cache->resolve('/foo.jpg', fn(): string => 'stable-etag', $fetchContents);

        self::assertSame('contents', file_get_contents($path));
        self::assertSame(1, $downloadCount);

        $cache->invalidate('/foo.jpg');
    }

    #[Test]
    public function resolveRedownloadsWhenEtagChangedAfterTtlExpiry(): void
    {
        $cache = new LocalFileCacheService(9004, -1);
        $cache->invalidate('/foo.jpg');

        $cache->resolve('/foo.jpg', fn(): string => 'etag-1', fn(): string => 'old contents');
        $path = $cache->resolve('/foo.jpg', fn(): string => 'etag-2', fn(): string => 'new contents');

        self::assertSame('new contents', file_get_contents($path));

        $cache->invalidate('/foo.jpg');
    }

    #[Test]
    public function invalidateRemovesCachedFile(): void
    {
        $cache = new LocalFileCacheService(9005, 3600);

        $path = $cache->resolve('/foo.jpg', fn(): ?string => null, fn(): string => 'contents');
        self::assertFileExists($path);

        $cache->invalidate('/foo.jpg');
        self::assertFileDoesNotExist($path);
    }
}
