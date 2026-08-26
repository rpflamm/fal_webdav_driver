<?php

declare(strict_types=1);

namespace RPflamm\FalWebDavDriver\Tests\Functional\Driver;

use PHPUnit\Framework\Attributes\Test;
use RPflamm\FalWebDavDriver\Driver\WebDavDriver;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Runs the driver against a real WebDAV server, started via
 * `Build/docker-compose.webdav.yml`. Skips itself if that server is not
 * reachable, so this test does not break CI/local runs without Docker.
 *
 * This deliberately extends UnitTestCase rather than FunctionalTestCase: the
 * driver is exercised directly (no ResourceStorage/sys_file_storage record
 * involved), so no TYPO3 database bootstrap is required - only real network
 * IO against the WebDAV server.
 *
 * Override connection details with WEBDAV_TEST_BASE_URI / _USERNAME / _PASSWORD
 * env vars. When running via ddev (this repository's setup), the container is
 * reachable from inside the web container at http://host.docker.internal:8091/.
 */
final class WebDavDriverFunctionalTest extends UnitTestCase
{
    private WebDavDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $baseUriEnv = getenv('WEBDAV_TEST_BASE_URI');
        $baseUri = $baseUriEnv !== false ? $baseUriEnv : 'http://127.0.0.1:8091/';
        $usernameEnv = getenv('WEBDAV_TEST_USERNAME');
        $username = $usernameEnv !== false ? $usernameEnv : 'webdav';
        $passwordEnv = getenv('WEBDAV_TEST_PASSWORD');
        $password = $passwordEnv !== false ? $passwordEnv : 'webdav-secret';

        $host = (string) parse_url($baseUri, PHP_URL_HOST);
        $parsedPort = parse_url($baseUri, PHP_URL_PORT);
        $port = $parsedPort !== null && $parsedPort !== false ? (int) $parsedPort : 80;
        $connection = @fsockopen($host, $port, $errno, $errstr, 1.0);
        if ($connection === false) {
            self::markTestSkipped(sprintf('WebDAV test server at "%s" is not reachable (%s). Start it via `docker compose -f Build/docker-compose.webdav.yml up -d`.', $baseUri, $errstr));
        }
        fclose($connection);

        $this->driver = new WebDavDriver([
            'baseUri' => $baseUri,
            'authType' => 'basic',
            'username' => $username,
            'password' => $password,
        ]);
        $this->driver->setStorageUid(999);
        $this->driver->processConfiguration();
        $this->driver->initialize();

        if ($this->driver->folderExists('/functional-test/')) {
            $this->driver->deleteFolder('/functional-test/', true);
        }
        $this->driver->createFolder('functional-test');
    }

    protected function tearDown(): void
    {
        if (isset($this->driver) && $this->driver->folderExists('/functional-test/')) {
            $this->driver->deleteFolder('/functional-test/', true);
        }

        parent::tearDown();
    }

    #[Test]
    public function uploadedFileCanBeReadBackWithMatchingContentsAndHash(): void
    {
        $this->driver->createFile('hello.txt', '/functional-test/');
        $this->driver->setFileContents('/functional-test/hello.txt', 'Hello WebDAV');

        self::assertTrue($this->driver->fileExists('/functional-test/hello.txt'));
        self::assertSame('Hello WebDAV', $this->driver->getFileContents('/functional-test/hello.txt'));
        self::assertSame(sha1('Hello WebDAV'), $this->driver->hash('/functional-test/hello.txt', 'sha1'));
    }

    #[Test]
    public function renameFileMovesContentUnderNewIdentifier(): void
    {
        $this->driver->createFile('original.txt', '/functional-test/');
        $this->driver->setFileContents('/functional-test/original.txt', 'content');

        $newIdentifier = $this->driver->renameFile('/functional-test/original.txt', 'renamed.txt');

        self::assertSame('/functional-test/renamed.txt', $newIdentifier);
        self::assertFalse($this->driver->fileExists('/functional-test/original.txt'));
        self::assertTrue($this->driver->fileExists('/functional-test/renamed.txt'));
    }

    #[Test]
    public function copyFileWithinStorageDuplicatesTheFile(): void
    {
        $this->driver->createFile('source.txt', '/functional-test/');
        $this->driver->setFileContents('/functional-test/source.txt', 'copy me');

        $copyIdentifier = $this->driver->copyFileWithinStorage('/functional-test/source.txt', '/functional-test/', 'copy.txt');

        self::assertTrue($this->driver->fileExists('/functional-test/source.txt'));
        self::assertSame('copy me', $this->driver->getFileContents($copyIdentifier));
    }

    #[Test]
    public function nestedFolderStructureIsListedAndDeletedRecursively(): void
    {
        $this->driver->createFolder('sub', '/functional-test/');
        $this->driver->createFile('nested.txt', '/functional-test/sub/');

        self::assertSame(['/functional-test/sub/' => '/functional-test/sub/'], $this->driver->getFoldersInFolder('/functional-test/'));
        self::assertSame(['/functional-test/sub/nested.txt'], $this->driver->getFilesInFolder('/functional-test/', 0, 0, true));

        $this->driver->deleteFolder('/functional-test/sub/', true);

        self::assertFalse($this->driver->folderExists('/functional-test/sub/'));
    }

    #[Test]
    public function deletingAFileMakesFileExistsReturnFalse(): void
    {
        $this->driver->createFile('to-delete.txt', '/functional-test/');
        self::assertTrue($this->driver->fileExists('/functional-test/to-delete.txt'));

        $this->driver->deleteFile('/functional-test/to-delete.txt');

        self::assertFalse($this->driver->fileExists('/functional-test/to-delete.txt'));
    }
}
