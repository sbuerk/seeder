..  include:: /Includes.rst.txt

..  _feature-seed-definition-format:

===============================
Feature: Seed definition format
===============================

Description
===========

A **seed set** describes the content of a TYPO3 installation in YAML. It is a
directory :file:`Configuration/Seeder/<name>/` inside any active extension, and
it is made of two kinds of file.

:file:`config.yml` describes the **set**:

..  code-block:: yaml
    :caption: packages/my_extension/Configuration/Seeder/demo/config.yml

    identifier: demo
    title: 'Demo page tree'
    description: 'Pages, content elements and the site they are reachable through.'

    scenarios:
      - Scenario.yaml

    files:
      - identifier: placeholder
        source: 'Files/placeholder.svg'
        folder: 'demo'

    sites:
      - identifier: main
        rootPage: 1000

Every key set of the descriptor is **closed** - at the top level, on a
:yaml:`files` entry and on a :yaml:`sites` entry alike. An unknown key is
refused, naming the known ones, because :yaml:`scenario:` instead of
:yaml:`scenarios:` would otherwise be an import that reports success and writes
nothing. :yaml:`identifier`, :yaml:`title` and :yaml:`scenarios` are required.

The scenario files it names describe the **records**, in the YAML scenario
format of :php:`typo3/testing-framework`:

..  code-block:: yaml
    :caption: packages/my_extension/Configuration/Seeder/demo/Scenario.yaml

    entitySettings:
      '*':
        nodeColumnName: 'pid'
        columnNames: {id: 'uid', language: 'sys_language_uid'}
        defaultValues: {pid: 0}
      page:
        isNode: true
        tableName: 'pages'
        parentColumnName: 'pid'
        languageColumnNames: ['l10n_parent', 'l10n_source']
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
              languageVariants:
                - self: {id: 1101, title: 'DE: Über uns', language: 1, slug: '/ueber-uns'}

That format is not this extension's invention: it is the one the TYPO3 Core
writes its own functional test fixtures in, and the scenario files shipped in
the core are worked examples of it.

Properties of the format:

*   :yaml:`entitySettings` declares how a table is written - its name, its node
    and parent columns, its column aliases, its language columns, its default
    values and its value instructions. The entry :yaml:`'*'` holds the defaults
    for the declared entities. An entity name is not a table name, so one table
    can be written under two names with different defaults.
*   :yaml:`entities` declares the records. Everything inside :yaml:`self:` that
    is not :yaml:`id` is a field and is written as it stands, so a table needs
    no support in this extension to be seedable.
*   :yaml:`children:` nests further records of the same entity through the
    parent column, and a nested :yaml:`entities:` block puts records of other
    entities onto a node. Records come out in the order they are declared.
*   :yaml:`languageVariants:` is a first-class translation construct: the
    columns named by :yaml:`languageColumnNames` are filled with the uids of
    the ancestors of the variant, so a translation and a translation of a
    translation both get the chain TYPO3 expects.
*   :yaml:`versionVariants:` writes a workspace version of a record, and
    :yaml:`version:` in place of :yaml:`self:` writes a record that only exists
    in a workspace.
*   :yaml:`actions:` becomes a :php:`DataHandler` command - :yaml:`move`,
    :yaml:`delete`, :yaml:`discard` - run after every record exists, so an
    action can name a record the same set creates.
*   **Every record has a uid before it is written**: the declared :yaml:`id`,
    or a dynamic one from ``10000`` upwards. Every one of them is checked
    against the installation up front, and an import that would collide is
    refused with the records that are in the way.
*   :yaml:`scenarios` may name several files. They are composed into **one**
    scenario: :yaml:`entitySettings` merged with the later file winning,
    :yaml:`entities` appended per entity name. One composed scenario rather
    than one per file, because the dynamic uids are handed out per scenario.
*   :yaml:`imports` splits the descriptor itself over several files, merging
    the imported lists into the importing one. A resource that cannot be read
    fails the import rather than being skipped.

Impact
======

Extensions can ship the content an installation starts from, and that content is
reviewable, versioned and reproducible like any other part of the repository -
in a format that a TYPO3 developer has most likely already written by hand, for
a functional test.

The complete reference is in the :ref:`Configuration <configuration>` chapter.
