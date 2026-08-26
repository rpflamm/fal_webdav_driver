<?php

declare(strict_types=1);

namespace RPflamm\FalWebDavDriver\Client;

use Sabre\DAV\Xml\Property\ResourceType;

/**
 * Translates a single PROPFIND result entry (as returned by
 * Sabre\DAV\Client::propFind()) into a normalized array of FAL-relevant
 * properties.
 */
final class ResponseTranslator
{
    /**
     * @param array<string, mixed> $properties Clark-notation WebDAV properties for one resource
     *
     * @return array{size: int, mtime: int, ctime: int, mimetype: string, isCollection: bool, etag: string|null}
     */
    public function translate(array $properties): array
    {
        $resourceType = $properties['{DAV:}resourcetype'] ?? null;
        $isCollection = $resourceType instanceof ResourceType && $resourceType->is('{DAV:}collection');

        $mtime = $this->parseDate($properties['{DAV:}getlastmodified'] ?? null);
        $ctime = $this->parseDate($properties['{DAV:}creationdate'] ?? null);

        $etag = $properties['{DAV:}getetag'] ?? null;

        return [
            'size' => (int) ($properties['{DAV:}getcontentlength'] ?? 0),
            'mtime' => $mtime,
            'ctime' => $ctime !== 0 ? $ctime : $mtime,
            'mimetype' => (string) ($properties['{DAV:}getcontenttype'] ?? ''),
            'isCollection' => $isCollection,
            'etag' => $etag !== null ? trim((string) $etag, '"') : null,
        ];
    }

    private function parseDate(mixed $value): int
    {
        if (!is_string($value) || $value === '') {
            return 0;
        }
        $timestamp = strtotime($value);

        return $timestamp !== false ? $timestamp : 0;
    }
}
