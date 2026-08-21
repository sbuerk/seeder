..  include:: /Includes.rst.txt

..  _start:

============
Data Factory
============

:Extension key:
    data_factory

:Package name:
    sbuerk/data-factory

:Version:
    |release|

:Language:
    en

:Author:
    Stefan Bürk

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

    This is the documentation of the **1.x** line. TYPO3 v14.3 is served by the
    **2.x** line - see :ref:`Compatibility <compatibility>`.

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

----

Getting help
============

Report a problem or ask a question in the
`issue tracker of the repository <https://github.com/sbuerk/data-factory/issues>`__.
An import that refuses to run says why and exits with a code of its own - quote
the message and the code, and the
:bash:`data-factory:import <identifier> --dry-run -v` output if you have it. The
exit codes are listed under
:ref:`data-factory:import <configuration-command-import>`.

Contributions are welcome: the development setup, the quality gates and the
commit message rules are in :file:`CONTRIBUTING.md` of the
`source repository <https://github.com/sbuerk/data-factory>`__.

..  toctree::
    :maxdepth: 2
    :titlesonly:
    :hidden:

    Introduction/Index
    Installation/Index
    Configuration/Index
    Changelog/Index
