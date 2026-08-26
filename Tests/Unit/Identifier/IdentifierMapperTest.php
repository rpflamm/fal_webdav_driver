<?php

declare(strict_types=1);

namespace RPflamm\FalWebDavDriver\Tests\Unit\Identifier;

use PHPUnit\Framework\Attributes\Test;
use RPflamm\FalWebDavDriver\Identifier\IdentifierMapper;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class IdentifierMapperTest extends UnitTestCase
{
    #[Test]
    public function toWebDavPathEncodesEachSegmentSeparately(): void
    {
        $mapper = new IdentifierMapper('https://dav.example.com/remote.php/dav/files/user/');

        self::assertSame(
            'Photos%20Folder/my%20file.jpg',
            $mapper->toWebDavPath('/Photos Folder/my file.jpg')
        );
    }

    #[Test]
    public function fromHrefStripsAbsoluteBaseUriAndDecodesSegments(): void
    {
        $mapper = new IdentifierMapper('https://dav.example.com/remote.php/dav/files/user/');

        self::assertSame(
            '/Photos Folder/my file.jpg',
            $mapper->fromHref('https://dav.example.com/remote.php/dav/files/user/Photos%20Folder/my%20file.jpg')
        );
    }

    #[Test]
    public function fromHrefStripsRelativeBasePath(): void
    {
        $mapper = new IdentifierMapper('https://dav.example.com/remote.php/dav/files/user/');

        self::assertSame(
            '/my-file.jpg',
            $mapper->fromHref('/remote.php/dav/files/user/my-file.jpg')
        );
    }

    #[Test]
    public function fromHrefFallsBackToUnknownPathWhenBaseUriDoesNotMatch(): void
    {
        $mapper = new IdentifierMapper('https://dav.example.com/remote.php/dav/files/user/');

        self::assertSame(
            '/other/path/file.txt',
            $mapper->fromHref('https://another-host.example.com/other/path/file.txt')
        );
    }
}
