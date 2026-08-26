.. _known-issues:

============
Known issues
============

.. _known-issues-hash:

hash() is not derived from the WebDAV ETag
============================================

A WebDAV ``ETag`` is not a reliable content hash across servers (some derive
it from inode + mtime rather than content). ``hash()`` therefore downloads
(or serves from the local cache) the file and computes a real ``sha1`` hash.
For a previously uncached, large file this can trigger a full download.

.. _known-issues-credentials:

Credentials are stored in plain text
======================================

Username, password and bearer token configured via the storage's FlexForm
are stored unencrypted in ``sys_file_storage.configuration``, consistent with
how other FlexForm-configured storage drivers handle credentials. Restrict
backend access to the File Storage module accordingly.

.. _known-issues-locking:

No WebDAV locking support
============================

The driver does not acquire WebDAV locks (``LOCK``/``UNLOCK``) before
writing. Concurrent edits from multiple TYPO3 instances or other WebDAV
clients against the same storage can race.
