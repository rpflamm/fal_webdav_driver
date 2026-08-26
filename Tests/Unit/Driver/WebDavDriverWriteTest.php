<?php

declare(strict_types=1);

namespace RPflamm\FalWebDavDriver\Tests\Unit\Driver;

use PHPUnit\Framework\Attributes\Test;
use RPflamm\FalWebDavDriver\Driver\WebDavDriver;
use RPflamm\FalWebDavDriver\Exception\WebDavConnectionException;
use Sabre\DAV\Client;
use Sabre\DAV\Xml\Property\ResourceType;
use Sabre\HTTP\ClientException;
use TYPO3\CMS\Core\Resource\Exception\ExistingTargetFileNameException;
use TYPO3\CMS\Core\Resource\Exception\FileOperationErrorException;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class WebDavDriverWriteTest extends UnitTestCase
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

    #[Test]
    public function createFolderIssuesMkcolAndReturnsTheNewIdentifier(): void
    {
        $clientMock = $this->createMock(Client::class);
        $clientMock->expects(self::once())
            ->method('request')
            ->with('MKCOL', 'Photos/')
            ->willReturn(['statusCode' => 201, 'body' => '', 'headers' => []]);
        $driver = $this->createDriver([], $clientMock, 9201);

        self::assertSame('/Photos/', $driver->createFolder('Photos'));
    }

    #[Test]
    public function createFolderRecursiveSkipsExistingSegments(): void
    {
        $clientMock = $this->createMock(Client::class);
        $clientMock->method('propFind')->willReturnCallback(function (string $path) {
            if ($path === 'A/') {
                return ['{DAV:}resourcetype' => new ResourceType('{DAV:}collection')];
            }
            throw new \Sabre\HTTP\ClientHttpException(new \Sabre\HTTP\Response(404));
        });
        $clientMock->expects(self::once())
            ->method('request')
            ->with('MKCOL', 'A/B/')
            ->willReturn(['statusCode' => 201, 'body' => '', 'headers' => []]);
        $driver = $this->createDriver([], $clientMock, 9202);

        self::assertSame('/A/B/', $driver->createFolder('A/B', '', true));
    }

    #[Test]
    public function deleteFolderRefusesNonEmptyFolderWithoutRecursiveFlag(): void
    {
        $clientMock = $this->createMock(Client::class);
        $clientMock->method('propFind')->willReturn([
            '/webdav/Photos/' => ['{DAV:}resourcetype' => new ResourceType('{DAV:}collection')],
            '/webdav/Photos/image.jpg' => ['{DAV:}resourcetype' => new ResourceType()],
        ]);
        $clientMock->expects(self::never())->method('request');
        $driver = $this->createDriver([], $clientMock, 9203);

        $this->expectException(FileOperationErrorException::class);
        $driver->deleteFolder('/Photos/', false);
    }

    #[Test]
    public function deleteFolderIssuesDeleteWhenRecursiveFlagIsSet(): void
    {
        $clientMock = $this->createMock(Client::class);
        $clientMock->method('propFind')->willReturn([
            '/webdav/Photos/' => ['{DAV:}resourcetype' => new ResourceType('{DAV:}collection')],
        ]);
        $clientMock->expects(self::once())
            ->method('request')
            ->with('DELETE', 'Photos/')
            ->willReturn(['statusCode' => 204, 'body' => '', 'headers' => []]);
        $driver = $this->createDriver([], $clientMock, 9204);

        self::assertTrue($driver->deleteFolder('/Photos/', true));
    }

    #[Test]
    public function deleteFileIssuesDeleteRequest(): void
    {
        $clientMock = $this->createMock(Client::class);
        $clientMock->expects(self::once())
            ->method('request')
            ->with('DELETE', 'notes.txt')
            ->willReturn(['statusCode' => 204, 'body' => '', 'headers' => []]);
        $driver = $this->createDriver([], $clientMock, 9205);

        self::assertTrue($driver->deleteFile('/notes.txt'));
    }

    #[Test]
    public function addFileUploadsLocalFileContentsAndRemovesOriginal(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'webdav-test-');
        self::assertNotFalse($tmpFile);
        file_put_contents($tmpFile, 'uploaded content');

        $clientMock = $this->createMock(Client::class);
        $clientMock->expects(self::once())
            ->method('request')
            ->with('PUT', 'photo.jpg', 'uploaded content')
            ->willReturn(['statusCode' => 201, 'body' => '', 'headers' => []]);
        $driver = $this->createDriver([], $clientMock, 9206);

        $identifier = $driver->addFile($tmpFile, '/', 'photo.jpg');

        self::assertSame('/photo.jpg', $identifier);
        self::assertFileDoesNotExist($tmpFile);
    }

    #[Test]
    public function createFileUploadsEmptyContents(): void
    {
        $clientMock = $this->createMock(Client::class);
        $clientMock->expects(self::once())
            ->method('request')
            ->with('PUT', 'empty.txt', '')
            ->willReturn(['statusCode' => 201, 'body' => '', 'headers' => []]);
        $driver = $this->createDriver([], $clientMock, 9207);

        self::assertSame('/empty.txt', $driver->createFile('empty.txt', '/'));
    }

    #[Test]
    public function setFileContentsUploadsAndReturnsByteLength(): void
    {
        $clientMock = $this->createMock(Client::class);
        $clientMock->expects(self::once())
            ->method('request')
            ->with('PUT', 'notes.txt', 'hello')
            ->willReturn(['statusCode' => 200, 'body' => '', 'headers' => []]);
        $driver = $this->createDriver([], $clientMock, 9208);

        self::assertSame(5, $driver->setFileContents('/notes.txt', 'hello'));
    }

    #[Test]
    public function moveFileWithinStorageSendsMoveWithDestinationHeader(): void
    {
        $clientMock = $this->createMock(Client::class);
        $clientMock->expects(self::once())
            ->method('request')
            ->with('MOVE', 'a.txt', null, ['Destination' => 'https://dav.example.com/webdav/b.txt', 'Overwrite' => 'F'])
            ->willReturn(['statusCode' => 201, 'body' => '', 'headers' => []]);
        $driver = $this->createDriver([], $clientMock, 9209);

        self::assertSame('/b.txt', $driver->moveFileWithinStorage('/a.txt', '/', 'b.txt'));
    }

    #[Test]
    public function moveFileWithinStorageMapsPreconditionFailedToExistingTargetException(): void
    {
        $clientStub = self::createStub(Client::class);
        $clientStub->method('request')->willReturn(['statusCode' => 412, 'body' => '', 'headers' => []]);
        $driver = $this->createDriver([], $clientStub, 9210);

        $this->expectException(ExistingTargetFileNameException::class);
        $driver->moveFileWithinStorage('/a.txt', '/', 'b.txt');
    }

    #[Test]
    public function copyFileWithinStorageSendsCopyWithDestinationHeader(): void
    {
        $clientMock = $this->createMock(Client::class);
        $clientMock->expects(self::once())
            ->method('request')
            ->with('COPY', 'a.txt', null, ['Destination' => 'https://dav.example.com/webdav/Archive/a.txt', 'Overwrite' => 'F'])
            ->willReturn(['statusCode' => 201, 'body' => '', 'headers' => []]);
        $driver = $this->createDriver([], $clientMock, 9211);

        self::assertSame('/Archive/a.txt', $driver->copyFileWithinStorage('/a.txt', '/Archive/', 'a.txt'));
    }

    #[Test]
    public function renameFolderBuildsIdentifierMapForAffectedEntries(): void
    {
        $clientMock = $this->createMock(Client::class);
        $clientMock->method('propFind')->willReturn([
            '/webdav/Photos/' => ['{DAV:}resourcetype' => new ResourceType('{DAV:}collection')],
            '/webdav/Photos/a.jpg' => ['{DAV:}resourcetype' => new ResourceType()],
        ]);
        $clientMock->expects(self::once())
            ->method('request')
            ->with('MOVE', 'Photos/', null, ['Destination' => 'https://dav.example.com/webdav/Album/', 'Overwrite' => 'F'])
            ->willReturn(['statusCode' => 201, 'body' => '', 'headers' => []]);
        $driver = $this->createDriver([], $clientMock, 9212);

        self::assertSame(
            ['/Photos/' => '/Album/', '/Photos/a.jpg' => '/Album/a.jpg'],
            $driver->renameFolder('/Photos/', 'Album')
        );
    }

    #[Test]
    public function copyFolderWithinStorageSendsCopyWithInfiniteDepth(): void
    {
        $clientMock = $this->createMock(Client::class);
        $clientMock->expects(self::once())
            ->method('request')
            ->with('COPY', 'Photos/', null, ['Depth' => 'infinity', 'Destination' => 'https://dav.example.com/webdav/Backup/Photos/', 'Overwrite' => 'F'])
            ->willReturn(['statusCode' => 201, 'body' => '', 'headers' => []]);
        $driver = $this->createDriver([], $clientMock, 9213);

        self::assertTrue($driver->copyFolderWithinStorage('/Photos/', '/Backup/', 'Photos'));
    }

    #[Test]
    public function writeConnectionFailureIsWrappedAsWebDavConnectionException(): void
    {
        $clientStub = self::createStub(Client::class);
        $clientStub->method('request')->willThrowException(new ClientException('Connection refused', 7));
        $driver = $this->createDriver([], $clientStub, 9214);

        $this->expectException(WebDavConnectionException::class);
        $driver->deleteFile('/notes.txt');
    }
}
