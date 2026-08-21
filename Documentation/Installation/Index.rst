..  include:: /Includes.rst.txt

..  _installation:

============
Installation
============

The extension has to be installed like any other TYPO3 CMS extension.

This is the documentation of branch ``1``, the 1.x line of the extension. It
requires ``typo3/cms-core`` ``^12.4.22 || ^13.4`` and PHP
``^8.1 || ^8.2 || ^8.3 || ^8.4``, so it installs on **TYPO3 v12.4.22 up to
v13.4** and **PHP 8.1 up to 8.4**. The v12 floor is a patch level rather than
plain ``12.4`` - worth noting when a project pins its core version. PHP 8.1
applies to TYPO3 v12 only, because ``typo3/cms-core`` 13.4 requires PHP
``^8.2``. See :ref:`Compatibility <compatibility>` for the full matrix, branch
``main`` included.

Composer mode
=============

Seeding demo, fixture and development data is usually a development concern, so
the extension normally belongs in :bash:`require-dev` of the project whose data
is rebuilt:

..  code-block:: bash

    composer require --dev sbuerk/seeder

Require it normally when a project provisions itself from a seed set — the
commands then exist in the deployed installation as well:

..  code-block:: bash

    composer require sbuerk/seeder

..  note::

    As long as no stable version has been released, the development version of
    the ``1`` branch has to be required explicitly - its branch alias maps
    ``^1.0@dev`` onto ``1.0.x-dev``:

    ..  code-block:: bash

        composer require --dev sbuerk/seeder:^1.0@dev

    This additionally requires ``minimum-stability`` to be set to ``dev``
    together with ``prefer-stable`` set to ``true`` in the root
    :file:`composer.json` file.

Classic mode
============

#.  **Get it from the Extension Manager**:
    Switch to the module :guilabel:`Admin Tools > Extensions`, switch to
    :guilabel:`Get Extensions` and search for the extension key
    *seeder*, then import the extension from the repository.

#.  **Get it from typo3.org**:
    You can always get the current version from `TER`_ by downloading the zip
    version. Upload the file afterwards in the Extension Manager.

..  _TER: https://extensions.typo3.org/extension/seeder

After the installation
======================

The extension needs no configuration of its own: it has no extension
configuration, no TypoScript and no backend module. Activating it adds the two
console commands, and nothing else in the installation changes until one of them
is run.

..  code-block:: bash

    vendor/bin/typo3 seeder:list

An installation whose extensions provide no seed set answers with
:bash:`No active extension provides a seed set.` and exits successfully — that is
the normal state of most installations. Writing a seed set is described in
:ref:`Configuration <configuration>`.

Requirements for an import
==========================

Two conditions have to be met for :bash:`seeder:import`, and both are refusals
with an explanation rather than silent failures:

*   **It runs on the command line, as an administrator.** The TYPO3 console
    application authenticates the :bash:`_cli_` backend user, which is an
    administrator by default. TYPO3 honours a declared uid only for an
    administrator and ignores it silently otherwise, which would write a page
    tree with different uids than the set declares.
*   **A file storage has to exist** when the set brings files. A new
    installation gets its :file:`fileadmin/` storage from :bash:`typo3 setup`; a
    set may also name the storage to write into.

See also
========

*   :ref:`Configuration <configuration>` — the seed definition format and the
    two commands
*   :ref:`Changelog <changelog>`
