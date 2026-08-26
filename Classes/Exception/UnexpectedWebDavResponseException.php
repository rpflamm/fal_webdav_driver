<?php

declare(strict_types=1);

namespace RPflamm\FalWebDavDriver\Exception;

/**
 * Thrown when the WebDAV server responds with an HTTP status that does not
 * map to any of the more specific FAL resource exceptions.
 */
final class UnexpectedWebDavResponseException extends \RuntimeException {}
