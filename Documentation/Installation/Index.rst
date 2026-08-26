.. _installation:

============
Installation
============

.. _installation-composer:

Composer installation
======================

Install the extension like any other Composer-based TYPO3 package:

.. code-block:: bash

    composer require rpflamm/fal-webdav-driver

If you are developing this extension from source instead of a released
package, add it as a `Composer path repository
<https://getcomposer.org/doc/05-repositories.md#path>`__ pointing at its
directory, then require it with a :code:`@dev` constraint.

.. note::
    Running under DDEV? Prefix Composer commands with :code:`ddev` (e.g.
    :code:`ddev composer require ...`).

Next, continue with :ref:`configuration <configuration>` to create your first
WebDAV file storage.
