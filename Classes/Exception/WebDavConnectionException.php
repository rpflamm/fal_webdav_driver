<?php

declare(strict_types=1);

namespace RPflamm\FalWebDavDriver\Exception;

/**
 * Thrown when the WebDAV server cannot be reached at all (DNS, TLS, timeout, ...),
 * as opposed to the server responding with an HTTP error status.
 */
final class WebDavConnectionException extends \RuntimeException {}
