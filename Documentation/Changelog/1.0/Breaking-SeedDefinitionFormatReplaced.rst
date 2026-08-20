..  include:: /Includes.rst.txt

..  _breaking-seed-definition-format-replaced:

=========================================
Breaking: Seed definition format replaced
=========================================

Description
===========

The custom seed definition format is gone. A seed set no longer describes its
records in :file:`config.yml`; it names one or more **scenario files**, written
in the YAML scenario format of :php:`typo3/testing-framework` - the format the
TYPO3 Core writes its own functional test fixtures in.

:file:`config.yml` keeps its role as the **set descriptor** and keeps the keys
:yaml:`identifier`, :yaml:`title`, :yaml:`description`, :yaml:`imports`,
:yaml:`files` and :yaml:`sites`. What it gains is the required key
:yaml:`scenarios`, a non-empty list of the files the records come from.

What is gone
------------

..  list-table::
    :header-rows: 1

    *   -   Gone
        -   Replaced by
    *   -   :yaml:`pages:` at set level
        -   :yaml:`scenarios:`, naming files that declare
            :yaml:`entitySettings` and :yaml:`entities`.
    *   -   :yaml:`children:` on a page
        -   :yaml:`children:` on an entity item, which is the same idea with a
            different parent pointer mechanism.
    *   -   :yaml:`content:` on a page
        -   A nested :yaml:`entities:` block naming an entity that writes
            :sql:`tt_content`.
    *   -   :yaml:`records:` on a page
        -   A nested :yaml:`entities:` block naming an entity for that table.
    *   -   :yaml:`table:` on a record
        -   :yaml:`tableName` in the :yaml:`entitySettings` of the entity. It
            is declared once per table instead of once per record.
    *   -   :yaml:`identifier:` on a record
        -   Nothing. A scenario record is identified by its uid.
    *   -   :yaml:`uid:` on a record
        -   :yaml:`id` inside :yaml:`self:`, which is the same value under the
            name the scenario format uses for it.
    *   -   :yaml:`inline:` on a record
        -   Nothing in this version. A relation is written by putting the uid
            of the target into the relation field.
    *   -   :yaml:`files:` **on a record**
        -   Nothing in this version. The set level :yaml:`files:` key is
            unchanged and still provisions files.
    *   -   A :yaml:`rootPage` naming a seed identifier
        -   A :yaml:`rootPage` naming a page **uid**: the :yaml:`id` an entity
            of the :sql:`pages` table declares.

The exception codes of the removed record parsing (``1787072830`` to
``1787072862``, ``1787072875``, ``1787072876``, ``1787072815`` and
``1787078001``) are gone with it.

How to migrate
--------------

Split the old :file:`config.yml` in two. What described the set stays, what
described records moves into a scenario file next to it.

..  code-block:: yaml
    :caption: Before: Configuration/Seeder/demo/config.yml

    identifier: demo
    title: 'Demo page tree'

    pages:
      - identifier: home
        uid: 1000
        title: 'Demo'
        slug: '/'
        is_siteroot: 1
        content:
          - identifier: home-heading
            CType: header
            header: 'A frontend to look at'
        records:
          - identifier: category-news
            table: sys_category
            title: 'News'
        children:
          - identifier: about
            uid: 1100
            title: 'About'
            slug: '/about'

    sites:
      - identifier: main
        rootPage: home

..  code-block:: yaml
    :caption: After: Configuration/Seeder/demo/config.yml

    identifier: demo
    title: 'Demo page tree'

    scenarios:
      - Scenario.yaml

    sites:
      - identifier: main
        rootPage: 1000

..  code-block:: yaml
    :caption: After: Configuration/Seeder/demo/Scenario.yaml

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
      category:
        tableName: 'sys_category'

    entities:
      page:
        - self: {id: 1000, title: 'Demo', slug: '/', is_siteroot: 1}
          entities:
            content:
              - self: {id: 2000, title: 'A frontend to look at', type: 'header'}
            category:
              - self: {id: 3000, title: 'News'}
          children:
            - self: {id: 1100, title: 'About', slug: '/about'}

Five things are worth checking while migrating:

*   **Every table needs an entity.** A table that was written with
    :yaml:`table: sys_category` needs an :yaml:`entitySettings` entry now.
    Listing it is not optional: an entity that appears in :yaml:`entities:` and
    not in :yaml:`entitySettings:` gets no defaults, no column aliases and no
    :yaml:`nodeColumnName`, so its records land on the page tree root.
*   **Records are no longer visible by default.** The old format wrote
    :yaml:`hidden: 0` unless a record said otherwise. Nothing does that now, so
    declare it in the :yaml:`defaultValues` of the entity.
*   **Uids are no longer optional.** Every record gets one - the declared
    :yaml:`id`, or a dynamic uid from ``10000`` upwards - and all of them are
    checked against the installation before anything is written. A set that
    used to declare a uid on two pages now suggests one for every record it
    writes.
*   **A site names a page uid.** Declare :yaml:`id` on the page that is the
    site root, and write that number into :yaml:`rootPage`. A site whose
    :yaml:`rootPage` no :sql:`pages` entity of the scenario declares is refused
    before anything is written.
*   **A** :yaml:`files` **entry no longer accepts an unknown key.** It may
    declare :yaml:`identifier`, :yaml:`source`, :yaml:`folder`, :yaml:`name`
    and :yaml:`storage`, and nothing else. A set carrying a typo there used to
    import - :yaml:`foldr:` put the file in the storage root and reported
    success - and is refused now, naming the key.

Impact
======

Every seed set written against the previous format has to be rewritten. There is
no compatibility layer and no deprecation phase: version 1.0 is unreleased, so
this affects installations running ``1.0.0-dev`` from the ``main`` branch, and
nothing that was ever released.

What the change buys is a format that is not this extension's invention: it is
the one the TYPO3 Core writes its functional test fixtures in, it is documented
by dozens of scenario files in the core, and it brings translations, workspace
versions and :php:`DataHandler` commands as first-class constructs rather than
as fields that happen to be writable.

The complete reference is in the :ref:`Configuration <configuration>` chapter.
