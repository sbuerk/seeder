..  include:: /Includes.rst.txt

..  _introduction:

============
Introduction
============

What does it do?
================

The :guilabel:`Data Factory` extension rebuilds the content of a TYPO3 installation
from YAML files that ship inside extensions: pages, content elements, records
of any table, the relations between them, files, the references attaching those
files to records, and site configurations.

That makes the starting state of an installation part of a repository instead
of something that is clicked together by hand - for a project template, for a
demo or reference installation, for the fixture data of a test or staging
instance, and for the "empty" instance a new developer starts from.

Two console commands are all there is to it:

..  list-table::
    :header-rows: 1

    *   -   Command
        -   Purpose
    *   -   :bash:`data-factory:list`
        -   List every seed set the active extensions provide.
    *   -   :bash:`data-factory:import <identifier>`
        -   Import one seed set into this installation.

What a seed set is
==================

A **seed set** is a directory :file:`Configuration/DataFactory/<name>/` inside any
active extension, with a :file:`config.yml` as its entry file. The descriptor
says what the set is and which files it is written from:

..  code-block:: yaml
    :caption: packages/my_extension/Configuration/DataFactory/demo/config.yml

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
    :caption: packages/my_extension/Configuration/DataFactory/demo/Scenario.yaml

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

A relation between records needs nothing beyond that. Because every record has
a uid before it is written, a parent names its children by writing their
declared ids into its relation field, and :php:`DataHandler` resolves the list
like any other relation - see
:ref:`Relations between records <configuration-inline-relations>`. A **file**
is the exception, and the one place where :file:`config.yml` has to say
something: the :yaml:`references` of a set attach a seeded file to a seeded
record, because a :sql:`sys_file_reference` points at its file by a uid the FAL
indexer only hands out while the file is being placed.

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
*   **A file reference reaches only the records of its own set.** The record a
    :yaml:`references` entry names is the one the same run writes; a file
    cannot be attached to a record that is already in the installation.
*   **A file reference is declared in** :file:`config.yml`, not in a scenario
    file. The scenario format has no concept of a file and does not gain one
    here, because a :sql:`sys_file_reference` points at its file by a uid the
    FAL indexer hands out while the file is being placed.
*   **Backend users** written by a set have to declare :yaml:`username` and
    :yaml:`password` themselves, because the import mode that suppresses the
    automatic site configuration also suppresses the generated credentials.

The two boundaries of the *format* - how far a file reference reaches, and why
it cannot live in a scenario file - are stated again where they are relevant, in
:ref:`What this version does not do <configuration-limits>`.

..  _compatibility:

Compatibility
=============

..  list-table::
    :header-rows: 1

    *   -   Branch
        -   State
        -   Extension
        -   TYPO3
        -   PHP
    *   -   main
        -   active
        -   2.x
        -   v13.4 / v14.3
        -   8.2 - 8.5
    *   -   1
        -   maintained
        -   1.x
        -   v12.4
        -   8.1 - 8.4
    *   -   1
        -   maintained
        -   1.x
        -   v13.4
        -   8.2 - 8.4

Branch ``1`` is the 1.x line and the one this documentation belongs to: TYPO3
v12.4 and v13.4. It carries one row per TYPO3 version, because the PHP ranges
differ - PHP 8.1 is supported for **TYPO3 v12 only**, as ``typo3/cms-core``
13.4 requires PHP ``^8.2`` and a v13 dependency set on PHP 8.1 cannot be
installed at all. The lowest supported TYPO3 v12 patch level is **12.4.22**.

Branch ``main`` is the 2.x line, on TYPO3 v13.4 and v14.3. One row is enough
there, because both of its core versions share the same PHP range.

Both lines are released. ``main`` is the active line and receives features and
fixes; branch ``1`` is maintained for installations still on TYPO3 v12.4 and
receives fixes. TYPO3 v13.4 is served by both, so an installation on v13.4 can
move between the lines without changing a seed set.

One code base serves both supported TYPO3 versions. Where an implementation has
to differ, the classes are split per core version - below :file:`Core12/` and
:file:`Core13/` - and the dependency injection container registers only the
directory matching the running installation. Three implementations are split
that way on this line: the YAML loader that reads a descriptor and a site
template, the call that places a seeded file into a file storage, and the
writing of a site configuration. None of it is visible in a seed set, or to
anything calling the commands.

..  _stability:

Stability
=========

The **supported interface** of this extension is the scenario format,
:file:`config.yml` and the two console commands with their options and exit
codes. A change to it that is not backwards compatible goes into a new major
version and carries a :file:`Breaking-*.rst` entry in the
:ref:`Changelog <changelog>`.

Everything below :file:`Classes/`, :file:`Core12/` and :file:`Core13/` is
:php:`@internal`. It is the implementation of that interface, it carries no
compatibility promise, and it may change in any release - a seed set never
touches it.
