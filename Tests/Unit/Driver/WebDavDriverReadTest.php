<?php

declare(strict_types=1);

namespace RPflamm\FalWebDavDriver\Tests\Unit\Driver;

use PHPUnit\Framework\Attributes\Test;
use RPflamm\FalWebDavDriver\Driver\WebDavDriver;
use RPflamm\FalWebDavDriver\Exception\WebDavConnectionException;
use Sabre\DAV\Client;
use Sabre\DAV\Xml\Property\ResourceType;
use Sabre\HTTP\ClientException;
use Sabre\HTTP\ClientHttpException;
use Sabre\HTTP\Response;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException;
use TYPO3\CMS\Core\Resource\Exception\FolderDoesNotExistException;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class WebDavDriverReadTest extends UnitTestCase
{
    /**
     * @param array<string, mixed> $configuration
     */
    private function createDriver(array $configuration, Client $client, int $storageUid): WebDavDriver
    {
        $driver = new WebDavDriver($configuration + ['baseUri' => 'https://dav.example.com/webdav/']);
        $driver->setStorageUid($storageUid);
        $driver->processConfiguration();
        $driver->initialize();

        $reflection = new \ReflectionProperty(WebDavDriver::class, 'client');
        $reflection->setValue($driver, $client);

        return $driver;
    }

    private function purgeCache(int $storageUid): void
    {
        $path = Environment::getVarPath() . '/transient/fal_webdav_driver/' . $storageUid . '/';
        $files = glob($path . '*');
        foreach ($files !== false ? $files : [] as $file) {
            @unlink($file);
        }
    }

    #[Test]
    public function getFileInfoByIdentifierBuildsExpectedProperties(): void
    {
        $clientMock = $this->createMock(Client::class);
        $clientMock->expects(self::once())->method('propFind')->willReturn([
            '{DAV:}getcontentlength' => '42',
            '{DAV:}getcontenttype' => 'text/plain',
            '{DAV:}getlastmodified' => 'Mon, 12 Jan 2026 10:00:00 GMT',
        ]);
        $driver = $this->createDriver([], $clientMock, 9101);

        $info = $driver->getFileInfoByIdentifier('/notes.txt');

        self::assertSame('/notes.txt', $info['identifier']);
        self::assertSame('notes.txt', $info['name']);
        self::assertSame(42, $info['size']);
        self::assertSame('text/plain', $info['mimetype']);
        self::assertSame(9101, $info['storage']);
    }

    #[Test]
    public function getFileInfoByIdentifierMapsNotFoundToFileDoesNotExistException(): void
    {
        $clientStub = self::createStub(Client::class);
        $clientStub->method('propFind')->willThrowException(new ClientHttpException(new Response(404)));
        $driver = $this->createDriver([], $clientStub, 9102);

        $this->expectException(FileDoesNotExistException::class);
        $driver->getFileInfoByIdentifier('/missing.txt');
    }

    #[Test]
    public function propFindConnectionFailureIsWrappedAsWebDavConnectionException(): void
    {
        $clientStub = self::createStub(Client::class);
        $clientStub->method('propFind')->willThrowException(new ClientException('Could not resolve host', 6));
        $driver = $this->createDriver([], $clientStub, 9103);

        $this->expectException(WebDavConnectionException::class);
        $driver->getFileInfoByIdentifier('/notes.txt');
    }

    #[Test]
    public function fileExistsReturnsFalseOnNotFound(): void
    {
        $clientStub = self::createStub(Client::class);
        $clientStub->method('propFind')->willThrowException(new ClientHttpException(new Response(404)));
        $driver = $this->createDriver([], $clientStub, 9104);

        self::assertFalse($driver->fileExists('/missing.txt'));
    }

    #[Test]
    public function fileExistsReturnsTrueWhenPropFindSucceeds(): void
    {
        $clientStub = self::createStub(Client::class);
        $clientStub->method('propFind')->willReturn(['{DAV:}getcontentlength' => '1']);
        $driver = $this->createDriver([], $clientStub, 9105);

        self::assertTrue($driver->fileExists('/notes.txt'));
    }

    #[Test]
    public function folderExistsReturnsFalseOnNotFound(): void
    {
        $clientStub = self::createStub(Client::class);
        $clientStub->method('propFind')->willThrowException(new ClientHttpException(new Response(404)));
        $driver = $this->createDriver([], $clientStub, 9106);

        self::assertFalse($driver->folderExists('/missing/'));
    }

    #[Test]
    public function getFolderInfoByIdentifierMapsNotFoundToFolderDoesNotExistException(): void
    {
        $clientStub = self::createStub(Client::class);
        $clientStub->method('propFind')->willThrowException(new ClientHttpException(new Response(404)));
        $driver = $this->createDriver([], $clientStub, 9107);

        $this->expectException(FolderDoesNotExistException::class);
        $driver->getFolderInfoByIdentifier('/missing/');
    }

    #[Test]
    public function getFilesAndFoldersInFolderSeparateEntriesAndExcludeTheFolderItself(): void
    {
        $clientMock = $this->createMock(Client::class);
        $clientMock->expects(self::exactly(2))->method('propFind')->willReturn([
            '/webdav/Photos/' => ['{DAV:}resourcetype' => new ResourceType('{DAV:}collection')],
            '/webdav/Photos/image.jpg' => [
                '{DAV:}resourcetype' => new ResourceType(),
                '{DAV:}getcontentlength' => '10',
            ],
            '/webdav/Photos/Sub/' => ['{DAV:}resourcetype' => new ResourceType('{DAV:}collection')],
        ]);
        $driver = $this->createDriver([], $clientMock, 9108);

        self::assertSame(['/Photos/image.jpg'], $driver->getFilesInFolder('/Photos/'));
        self::assertSame(['/Photos/Sub/' => '/Photos/Sub/'], $driver->getFoldersInFolder('/Photos/'));
    }

    #[Test]
    public function isFolderEmptyReturnsTrueWhenOnlyTheFolderItselfIsListed(): void
    {
        $clientStub = self::createStub(Client::class);
        $clientStub->method('propFind')->willReturn([
            '/webdav/Photos/' => ['{DAV:}resourcetype' => new ResourceType('{DAV:}collection')],
        ]);
        $driver = $this->createDriver([], $clientStub, 9109);

        self::assertTrue($driver->isFolderEmpty('/Photos/'));
    }

    #[Test]
    public function getFilesInFolderSortsByNameAndSupportsReverse(): void
    {
        $clientStub = self::createStub(Client::class);
        $clientStub->method('propFind')->willReturn([
            '/webdav/b.txt' => ['{DAV:}resourcetype' => new ResourceType()],
            '/webdav/a.txt' => ['{DAV:}resourcetype' => new ResourceType()],
        ]);
        $driver = $this->createDriver([], $clientStub, 9110);

        self::assertSame(['/a.txt', '/b.txt'], $driver->getFilesInFolder('/', 0, 0, false, [], 'name'));
        self::assertSame(['/b.txt', '/a.txt'], $driver->getFilesInFolder('/', 0, 0, false, [], 'name', true));
    }

    #[Test]
    public function getFileContentsDownloadsAndCachesTheFile(): void
    {
        $this->purgeCache(9111);
        $clientMock = $this->createMock(Client::class);
        $clientMock->expects(self::once())->method('propFind')->willReturn(['{DAV:}getetag' => '"abc"']);
        $clientMock->expects(self::once())->method('request')->willReturn([
            'statusCode' => 200,
            'body' => 'hello world',
            'headers' => [],
        ]);
        $driver = $this->createDriver([], $clientMock, 9111);

        self::assertSame('hello world', $driver->getFileContents('/notes.txt'));

        $this->purgeCache(9111);
    }

    #[Test]
    public function hashComputesShaOneOfTheDownloadedContent(): void
    {
        $this->purgeCache(9112);
        $clientStub = self::createStub(Client::class);
        $clientStub->method('propFind')->willReturn(['{DAV:}getetag' => '"abc"']);
        $clientStub->method('request')->willReturn([
            'statusCode' => 200,
            'body' => 'hello world',
            'headers' => [],
        ]);
        $driver = $this->createDriver([], $clientStub, 9112);

        self::assertSame(sha1('hello world'), $driver->hash('/notes.txt', 'sha1'));

        $this->purgeCache(9112);
    }

    #[Test]
    public function getPublicUrlReturnsNullWithoutPublicBaseUri(): void
    {
        $clientStub = self::createStub(Client::class);
        $driver = $this->createDriver([], $clientStub, 9113);

        self::assertNull($driver->getPublicUrl('/notes.txt'));
    }

    #[Test]
    public function getPublicUrlBuildsUrlFromPublicBaseUri(): void
    {
        $clientStub = self::createStub(Client::class);
        $driver = $this->createDriver(['publicBaseUri' => 'https://public.example.com/files'], $clientStub, 9114);

        self::assertSame('https://public.example.com/files/notes.txt', $driver->getPublicUrl('/notes.txt'));
    }
}
