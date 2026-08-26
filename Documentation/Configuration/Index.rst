.. _configuration:

=============
Configuration
=============

After installing the extension, create a new **File Storage**
(:code:`sys_file_storage`) record in the TYPO3 backend
(:guilabel:`File > Filestorages`) and choose **WebDAV** as the storage driver.

.. _configuration-fields:

Storage configuration fields
=============================

.. confval-menu::
    :name: configuration-menu
    :display: table

.. confval:: Base URI

    :Required: true

    The WebDAV endpoint, e.g. :code:`https://cloud.example.com/remote.php/dav/files/user/`
    for a Nextcloud/ownCloud instance.

.. confval:: Authentication type

    :Default: Basic authentication

    Either :code:`Basic authentication` (username/password) or
    :code:`Bearer token`.

.. confval:: Username / Password

    Used when the authentication type is Basic. Leave both empty for
    anonymous/unauthenticated WebDAV access.

.. confval:: Bearer token

    Required when the authentication type is Bearer.

.. confval:: Verify SSL certificate

    :Default: enabled

    Disable only for self-signed or internal WebDAV servers.

.. confval:: Case-sensitive identifiers

    :Default: enabled

    Should match the actual case-sensitivity behaviour of the WebDAV server.

.. confval:: Local cache lifetime

    :Default: 3600 (seconds)

    How long a downloaded file is served from the local cache
    (:file:`var/transient/fal_webdav_driver/<storage-uid>/`) before being
    re-validated against the server via its WebDAV ETag. A GET request is
    only re-issued when the ETag has changed.

.. confval:: Public base URI

    Only needed if the storage should be publicly accessible under a URL
    different from the base URI (e.g. a CDN-fronted WebDAV backend). Leave
    empty if the storage should not be treated as public.

.. important::
    Username, password and bearer token are stored **in plain text** in the
    storage configuration (:code:`sys_file_storage.configuration`), the same
    way other credential-based storage drivers behave when configured via
    FlexForm. Restrict backend access to the File Storage module accordingly.
