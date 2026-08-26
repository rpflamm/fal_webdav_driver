# FAL WebDAV Driver

A TYPO3 extension (`fal_webdav_driver`) that adds a FAL storage driver for **WebDAV**, so a WebDAV
directory (Nextcloud, ownCloud, Apache `mod_dav`, ...) can be mounted directly into the TYPO3
fileadmin like any other `sys_file_storage`.

Compatible with **TYPO3 v14.3 LTS**.

## Status

This extension is under active development. Read/write file and folder operations, Basic and
Bearer authentication, and a local caching layer for file processing are being added incrementally.

## Quick install

```bash
composer require rpflamm/fal-webdav-driver
```

If you develop this extension from source rather than a released package, add it as a
[Composer path repository](https://getcomposer.org/doc/05-repositories.md#path) instead. Running
under DDEV? Prefix any `composer`/`vendor/bin/typo3` command below with `ddev`.

## Configuration

After installing the extension, create a new File Storage (`sys_file_storage`) record in the
TYPO3 backend and choose **WebDAV** as the driver. Configure the WebDAV base URI and the
authentication method (Basic or Bearer token) in the storage's configuration.
