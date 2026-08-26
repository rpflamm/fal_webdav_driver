<?php

declare(strict_types=1);

namespace RPflamm\FalWebDavDriver\Identifier;

/**
 * Translates between FAL identifiers (e.g. "/Photos/foo.jpg") and WebDAV
 * request paths/hrefs, which are URL-encoded per path segment and may be
 * returned by the server as absolute or relative hrefs.
 */
final class IdentifierMapper
{
    public function __construct(private readonly string $baseUri) {}

    /**
     * @param non-empty-string $identifier
     *
     * @return non-empty-string
     */
    public function toWebDavPath(string $identifier): string
    {
        $segments = array_filter(explode('/', $identifier), static fn(string $segment): bool => $segment !== '');
        $encodedSegments = array_map(rawurlencode(...), $segments);

        return implode('/', $encodedSegments);
    }

    /**
     * @param non-empty-string $href
     *
     * @return non-empty-string
     */
    public function fromHref(string $href): string
    {
        $path = rawurldecode($href);
        $basePath = (string) parse_url($this->baseUri, PHP_URL_PATH);

        if ($basePath !== '' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath));
        } elseif (str_starts_with($path, $this->baseUri)) {
            $path = substr($path, strlen($this->baseUri));
        } else {
            $hrefPath = (string) parse_url($path, PHP_URL_PATH);
            if ($hrefPath !== '') {
                $path = $hrefPath;
            }
        }

        return '/' . ltrim($path, '/');
    }
}
