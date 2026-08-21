..  include:: /Includes.rst.txt

..  _start:

======
Seeder
======

:Extension key:
    seeder

:Package name:
    sbuerk/seeder

:Version:
    |release|

:Language:
    en

:Author:
    sbuerk

:License:
    This document is published under the
    `Open Content License <https://www.openhub.net/licenses/opl>`__.

:Rendered:
    |today|

----

Seeds a TYPO3 installation — pages, content elements, records of any table,
files and site configurations — from YAML definitions shipped inside extensions,
so that the content an installation starts from lives in a repository instead of
being clicked together by hand. Supports TYPO3 v12.4 and v13.4 within one code
base.

..  note::

    This extension has not reached a stable release yet. The seed definition
    format and the public API may change without a deprecation phase until the
    first stable release.

----

..  card-grid::
    :columns: 1
    :columns-md: 2
    :gap: 4
    :class: pb-4
    :card-height: 100

    ..  card:: :ref:`Introduction <introduction>`

        Learn what the extension provides, what a seed set is and which TYPO3
        and PHP versions are supported.

    ..  card:: :ref:`Installation <installation>`

        Install the extension in your TYPO3 installation.

    ..  card:: :ref:`Configuration <configuration>`

        Write a seed set: the YAML format, the file and site configuration
        keys, and the two console commands.

    ..  card:: :ref:`Changelog <changelog>`

        Overview of the changes per released version.

..  toctree::
    :maxdepth: 2
    :titlesonly:
    :hidden:

    Introduction/Index
    Installation/Index
    Configuration/Index
    Changelog/Index
