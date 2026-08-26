# FAL WebDAV Driver

A TYPO3 extension (`fal_webdav_driver`) that adds a FAL storage driver for **WebDAV**, so a WebDAV
directory (Nextcloud, ownCloud, Apache `mod_dav`, ...) can be mounted directly into the TYPO3
fileadmin like any other `sys_file_storage`.

Compatible with **TYPO3 v14.3 LTS**.

## Features

- Full read/write FAL driver (list, download, upload, rename, move, copy, delete) backed by
  [`sabre/dav`](https://sabre.io/).
- Basic authentication (username/password) or Bearer token authentication, configurable per storage.
- Local caching of downloaded file contents (TTL + ETag revalidation) so repeated reads and image
  processing do not re-download unchanged files on every request.

## Quick install

```bash
composer require rpflamm/fal-webdav-driver
```

If you develop this extension from source rather than a released package, add it as a
[Composer path repository](https://getcomposer.org/doc/05-repositories.md#path) instead. Running
under DDEV? Prefix any `composer`/`vendor/bin/typo3` command below with `ddev`.

## Configuration

After installing the extension, create a new File Storage (`sys_file_storage`) record in the
TYPO3 backend and choose **WebDAV** as the driver. Available configuration fields:

| Field | Description |
| --- | --- |
| Base URI | The WebDAV endpoint, e.g. `https://cloud.example.com/remote.php/dav/files/user/`. |
| Authentication type | `Basic authentication` or `Bearer token`. |
| Username / Password | Used when authentication type is Basic. |
| Bearer token | Used when authentication type is Bearer; required in that case. |
| Verify SSL certificate | Disable only for self-signed/internal WebDAV servers. |
| Case-sensitive identifiers | Should match the actual behaviour of the WebDAV server. |
| Local cache lifetime | Seconds a downloaded file is served from local cache before being re-validated via ETag. |
| Public base URI | Only needed if the storage should be publicly accessible under a different URL than the base URI. Leave empty for non-public storages. |

Username, password and bearer token are stored **in plain text** in the storage configuration
(`sys_file_storage.configuration`), the same way other credential-based storage drivers behave when
configured via FlexForm. Restrict backend access to the File Storage module accordingly.

## Known limitations

- `hash()` is not derived from the WebDAV ETag (which is not a reliable content hash across
  servers). It downloads/caches the file and computes a real `sha1` hash locally, which can trigger
  a full download for large, previously uncached files.

## Development notes

This project was developed with the assistance of AI coding agents; all generated code has been reviewed by a human before being committed.