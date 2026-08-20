..  include:: /Includes.rst.txt

..  _introduction:

============
Introduction
============

What does it do?
================

The :guilabel:`Seeder` extension rebuilds the content of a TYPO3 installation
from YAML files that ship inside extensions: pages, content elements, records
of any table, files and site configurations.

That makes the starting state of an installation part of a repository instead
of something that is clicked together by hand - for a project template, for a
demo or reference installation, for the fixture data of a test or staging
instance, and for the "empty" instance a new developer starts from.

Two console commands are all there is to it:

..  list-table::
    :header-rows: 1

    *   -   Command
        -   Purpose
    *   -   :bash:`seeder:list`
        -   List every seed set the active extensions provide.
    *   -   :bash:`seeder:import <identifier>`
        -   Import one seed set into this installation.

What a seed set is
==================

A **seed set** is a directory :file:`Configuration/Seeder/<name>/` inside any
active extension, with a :file:`config.yml` as its entry file. The descriptor
says what the set is and which files it is written from:

..  code-block:: yaml
    :caption: packages/my_extension/Configuration/Seeder/demo/config.yml

    identifier: demo
    title: 'Demo page tree'

    scenarios:
      - Scenario.yaml

    sites:
      - identifier: main
        rootPage: 1000

The records live in the scenario files it names, written in the YAML scenario
format of :php:`typo3/testing-framework` - the format the TYPO3 Core writes its
own functional test fixtures in:

..  code-block:: yaml
    :caption: packages/my_extension/Configuration/Seeder/demo/Scenario.yaml

    entitySettings:
      '*':
        nodeColumnName: 'pid'
        columnNames: {id: 'uid'}
        defaultValues: {pid: 0}
      page:
        isNode: true
        tableName: 'pages'
        parentColumnName: 'pid'
        defaultValues: {hidden: 0, doktype: 1}
      content:
        tableName: 'tt_content'
        columnNames: {title: 'header', type: 'CType'}
        defaultValues: {hidden: 0, colPos: 0}

    entities:
      page:
        - self: {id: 1000, title: 'Demo', slug: '/', is_siteroot: 1}
          entities:
            content:
              - self: {id: 2000, title: 'A frontend to look at', type: 'header'}
          children:
            - self: {id: 1100, title: 'About', slug: '/about'}

An extension therefore carries the data it needs with it, and nothing has to be
registered anywhere: the set is available as soon as the extension is installed
and activated. The complete format is described in
:ref:`Configuration <configuration>`.

Everything the extension writes goes through the TYPO3 :php:`DataHandler` and
the file storage API rather than through direct database inserts. Slugs are
generated, TCA defaults and evaluations are applied, the sorting is computed,
relations are resolved, the reference index is updated, the caches are flushed
and a copied file is indexed - the result is what an editor entering the same
content would have produced, not rows that merely look like it.

Reproducible uids
=================

Every record of a seed set has a uid before it is written: the :yaml:`id` its
scenario declares, or one handed out from ``10000`` upwards. That is what makes
a seeded page tree the *same* page tree in every installation, so a site
configuration, a TypoScript condition or a test may refer to a page by its
number.

Because TYPO3 treats such a uid as a suggestion rather than as a demand, an
import refuses to run when one of the suggested uids is already used in its
table, and it names the records that are in the way. A deleted record occupies
its uid as much as any other one.

What it does not do
===================

*   **Seeding writes, it does not synchronise.** An existing page tree is never
    reconciled against a definition, and no import is idempotent.
*   **Nothing is deleted or overwritten.** A uid collision and an existing site
    identifier are both refusals.
*   **A record cannot attach a file.** A set copies and indexes the files it
    ships, so a :sql:`sys_file` row exists - but there is no way yet to hang a
    :sql:`sys_file_reference` onto a record's field from a scenario.
*   **There is no inline relation construct.** Every record has a uid before it
    is written, so a relation is expressed by writing that uid into the
    relation field. What is missing is a nesting construct that creates the
    child and the relation in one declaration.
*   **Backend users** written by a set have to declare :yaml:`username` and
    :yaml:`password` themselves, because the import mode that suppresses the
    automatic site configuration also suppresses the generated credentials.

Compatibility
=============

..  list-table::
    :header-rows: 1

    *   -   Branch
        -   Extension
        -   TYPO3
        -   PHP
    *   -   main
        -   1.x
        -   v13 / v14
        -   8.2 - 8.5

One code base serves both supported TYPO3 versions. Where an implementation has
to differ, the classes are split per core version and the dependency injection
container registers the ones matching the running installation - none of which
is visible in a seed set.

..  note::

    The extension has not reached a stable release yet. The seed definition
    format and the public API may still change without a deprecation phase.

Contributing
============

Contributions are welcome. The development setup, the quality gates and the
commit message rules are described in the :file:`CONTRIBUTING.md` file of the
`source repository <https://github.com/sbuerk/seeder>`__.
