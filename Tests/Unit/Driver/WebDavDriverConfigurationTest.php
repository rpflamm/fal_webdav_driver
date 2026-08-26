<?php

declare(strict_types=1);

namespace RPflamm\FalWebDavDriver\Tests\Unit\Driver;

use PHPUnit\Framework\Attributes\Test;
use RPflamm\FalWebDavDriver\Driver\WebDavDriver;
use Sabre\DAV\Client;
use Sabre\DAV\Xml\Property\ResourceType;
use Sabre\HTTP\ClientHttpException;
use Sabre\HTTP\Response;
use TYPO3\CMS\Core\Resource\Exception\InvalidConfigurationException;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class WebDavDriverConfigurationTest extends UnitTestCase
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
    public function processConfigurationThrowsWithoutBearerTokenWhenAuthTypeIsBearer(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new WebDavDriver(['baseUri' => 'https://dav.example.com/webdav/', 'authType' => 'bearer']))->processConfiguration();
    }

    #[Test]
    public function processConfigurationSucceedsWithBearerTokenWhenAuthTypeIsBearer(): void
    {
        $driver = new WebDavDriver([
            'baseUri' => 'https://dav.example.com/webdav/',
            'authType' => 'bearer',
            'bearerToken' => 'secret-token',
        ]);

        $driver->processConfiguration();
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function getDefaultFolderReturnsUserUploadWithoutCreatingItWhenItAlreadyExists(): void
    {
        $clientMock = $this->createMock(Client::class);
        $clientMock->method('propFind')->willReturn(['{DAV:}resourcetype' => new ResourceType('{DAV:}collection')]);
        $clientMock->expects(self::never())->method('request');
        $driver = $this->createDriver([], $clientMock, 9301);

        self::assertSame('/user_upload/', $driver->getDefaultFolder());
    }

    #[Test]
    public function getDefaultFolderCreatesUserUploadWhenMissing(): void
    {
        $clientMock = $this->createMock(Client::class);
        $clientMock->method('propFind')->willThrowException(new ClientHttpException(new Response(404)));
        $clientMock->expects(self::once())
            ->method('request')
            ->with('MKCOL', 'user_upload/')
            ->willReturn(['statusCode' => 201, 'body' => '', 'headers' => []]);
        $driver = $this->createDriver([], $clientMock, 9302);

        self::assertSame('/user_upload/', $driver->getDefaultFolder());
    }
}
