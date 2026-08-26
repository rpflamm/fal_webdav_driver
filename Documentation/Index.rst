.. _start:

=================
FAL WebDAV Driver
=================

:Extension key:
    fal_webdav_driver

:Package name:
    rpflamm/fal-webdav-driver

:Version:
    1.0.0

:Language:
    en

:Author:
    RPflamm

:License:
    This document is published under the
    `CC BY-NC-SA 4.0 <https://creativecommons.org/licenses/by-nc-sa/4.0/>`__
    license.

:Rendered:
    |today|

----

This extension adds a FAL storage driver for **WebDAV**, so a WebDAV
directory (Nextcloud, ownCloud, Apache ``mod_dav``, ...) can be mounted
directly into the TYPO3 fileadmin like any other file storage: browsing,
uploading, renaming, moving, copying and deleting files and folders all work
through the WebDAV protocol via `sabre/dav <https://sabre.io/>`__.

.. card-grid::
    :columns: 1
    :columns-md: 2
    :gap: 4
    :class: pb-4
    :card-height: 100

    .. card:: :ref:`Installation <installation>`

        Installing the extension via Composer.

    .. card:: :ref:`Configuration <configuration>`

        Setting up a WebDAV file storage: base URI, authentication, caching.

    .. card:: :ref:`Known issues <known-issues>`

        Limitations to be aware of before relying on this driver in production.

.. toctree::
    :hidden:

    Installation/Index
    Configuration/Index
    KnownIssues/Index
