..  include:: /Includes.rst.txt

..  _configuration:

=============
Configuration
=============

The extension itself has nothing to configure. What is configured is the data:
a **seed set**, written in YAML, shipped by an extension, and read by the two
console commands.

This chapter is the reference for that format and for the commands.

..  contents::
    :local:
    :depth: 2

..  _configuration-layout:

Where a seed set lives
======================

A seed set is a directory :file:`Configuration/Seeder/<name>/` inside any
active extension, with :file:`config.yml` as its entry file:

..  code-block:: none

    packages/my_extension/Configuration/Seeder/demo/
    ├── config.yml               entry file, and the only mandatory one
    ├── Pages.yaml               optional, pulled in through "imports"
    ├── Files/
    │   └── placeholder.svg      resources named by "files"
    └── Sites/
        └── main/
            ├── config.yaml      site configuration template
            └── settings.yaml    optional site settings

Every relative path inside a set — a file :yaml:`source`, a site
:yaml:`template`, an :yaml:`imports` resource — is resolved against the
directory holding the **entry file**, not against the file declaring it. A set
can therefore be moved or renamed without touching a single path inside it.
:file:`EXT:` paths are accepted everywhere a path is.

A set is found because the extension providing it is installed and activated.
There is no path to configure and nothing to register. A directory below
:file:`Configuration/Seeder/` without a :file:`config.yml` is not a set and is
passed over, which is what lets a set keep partials next to itself.

..  _configuration-set-level:

The set level
=============

..  code-block:: yaml
    :caption: Configuration/Seeder/demo/config.yml

    identifier: demo
    title: 'Demo page tree'
    description: 'Pages, content elements and the site they are reachable through.'

    imports:
      - { resource: Pages.yaml }

    files: []
    pages: []
    sites: []

..  list-table::
    :header-rows: 1

    *   -   Key
        -   Required
        -   Meaning
    *   -   :yaml:`identifier`
        -   yes
        -   Globally unique across all active extensions. It is declared, never
            derived from the directory name — otherwise a collision between two
            extensions would be silent.
    *   -   :yaml:`title`
        -   yes
        -   Shown by :bash:`seeder:list`.
    *   -   :yaml:`description`
        -   no
        -   Free text describing the set.
    *   -   :yaml:`imports`
        -   no
        -   Further YAML files merged into this one.
    *   -   :yaml:`files`
        -   no
        -   Files copied into a file storage before any record is written.
    *   -   :yaml:`pages`
        -   no
        -   The page tree of the set.
    *   -   :yaml:`sites`
        -   no
        -   Site configurations written after the records.

This list is **closed**: any other key at this level is refused, naming the
known ones. That is deliberate — :yaml:`page:` instead of :yaml:`pages:` would
otherwise be an import that reports success and writes nothing.

The three metadata keys have to be declared in :file:`config.yml` itself. They
cannot be pulled in through :yaml:`imports`, because listing the sets of an
installation reads them without following imports.

..  _configuration-records:

Records
=======

..  code-block:: yaml

    pages:
      - identifier: home            # required, unique across the whole set
        uid: 1                      # optional, the uid to write
        title: 'Demo'               # any non-structural key is a field
        slug: '/'
        is_siteroot: 1
        files:                      # file references, per field
          media:
            - placeholder
        content:                    # tt_content records on this page
          - identifier: home-heading
            CType: header
            header: 'A frontend to look at'
        records:                    # records of any table on this page
          - identifier: category-news
            table: sys_category
            title: 'News'
        inline:                     # children of a relation, per parent field
          tx_example_items:
            - identifier: item-docs
              table: tx_example_item
              title: 'Documentation'
        children:                   # sub pages
          - identifier: about
            title: 'About'
            slug: '/about'

**Every key that is not structural is a field of the record and is written as
it stands.** A table needs no support in this extension to be seedable: if the
column exists and TYPO3 accepts the value, the seed can write it. There is no
allow list to extend and no mapping to maintain.

The structural keys are:

..  list-table::
    :header-rows: 1

    *   -   Key
        -   Structural on
        -   Meaning
    *   -   :yaml:`identifier`
        -   every record
        -   Names the record inside the set. Letters, digits and dashes only,
            starting with a letter or a digit, and unique across the whole
            definition.
    *   -   :yaml:`uid`
        -   every record
        -   The uid to write. A positive integer.
    *   -   :yaml:`children`
        -   every record
        -   Sub pages of the declaring page.
    *   -   :yaml:`content`
        -   every record
        -   :sql:`tt_content` records on the declaring page.
    *   -   :yaml:`files`
        -   every record
        -   File references, as a map of field name to references.
    *   -   :yaml:`inline`
        -   every record
        -   Inline children, as a map of the parent field to child records.
    *   -   :yaml:`table`
        -   an :yaml:`inline` or :yaml:`records` child
        -   The table the child belongs to. It is never inferred.
    *   -   :yaml:`records`
        -   a record of the :sql:`pages` table
        -   Records of any table on the declaring page.

The last two are decided per level rather than once, because both names exist as
real columns elsewhere: :sql:`tt_content` and :sql:`pages` carry fields whose
name begins with ``table``, and :sql:`tt_content` has a :sql:`records` column —
the one the *Insert records* element uses. On any other record, those two keys
are ordinary fields.

A field value has to be a scalar or empty. A relation is expressed with
:yaml:`inline`, with :yaml:`files`, or by writing the uid of the target into the
relation field like any other value.

..  warning::

    An identifier may contain **no underscore**. It becomes part of the
    placeholder TYPO3 resolves relations by, and an underscore in that
    placeholder makes the relation resolve to nothing — without an error. The
    seed is refused with a message instead. Site identifiers are exempt: they
    never reach such a placeholder.

Nesting
-------

..  list-table::
    :header-rows: 1

    *   -   Key
        -   Where the records end up
    *   -   :yaml:`children`
        -   As :sql:`pages` records below the declaring page.
    *   -   :yaml:`content`
        -   As :sql:`tt_content` records on the declaring page.
    *   -   :yaml:`records`
        -   As records of the declared table on the declaring page.
    *   -   :yaml:`inline`
        -   Tied to the parent through the named field. Their page is the page
            the **parent** sits on, because a relation is not a containment.

Records come out in the order they are declared, and pages, content elements and
records of further tables on one page do not disturb each other's sorting. The
order of inline children comes from the order they are declared in as well.

An inline child may itself declare :yaml:`files` and :yaml:`inline`.

..  _configuration-uids:

Declared uids
-------------

A declared :yaml:`uid` makes a seeded page tree reproducible: the same set
produces the same uids in every installation, so a site configuration, a
TypoScript condition or a test may refer to a page by its number.

TYPO3 treats such a uid as a *suggestion*. If the uid is already used in its
table, the record is not written elsewhere — the insert fails. The import
therefore checks every declared uid up front and refuses when one is taken,
naming the records in the way:

..  code-block:: none

    [ERROR] The seed set "demo" suggests 2 uids this installation already uses.

     ---------- ----- -------------------
      Table      Uid   Occupied by
     ---------- ----- -------------------
      pages      1     Company site
      pages      2     Products
     ---------- ----- -------------------

Nothing is written in that case. :bash:`--force` imports anyway: every record of
a **table something collides in** is written with a free uid instead of the
declared one, a table nothing collides in keeps its declared uids, and nothing
that is in the way is deleted or changed.

A deleted record occupies its uid as much as any other one — the row is still
there.

..  _configuration-files:

Files
=====

..  code-block:: yaml

    files:
      - identifier: placeholder             # required, unique among the files
        source: 'Files/placeholder.svg'     # required; relative to the set, or EXT:
        folder: 'demo'                      # optional, default the storage root
        name: 'placeholder.svg'             # optional, default the source basename
        storage: 1                          # optional, default the default storage

Files are copied before the records are written, through the file storage API,
which is what indexes them — a file copied into :file:`fileadmin/` by hand
exists on disk and does not exist for TYPO3. A missing target folder is created,
an existing file of the same name is replaced, and the source file in the
extension is left where it is.

A record points at a seeded file per field:

..  code-block:: yaml

    files:
      media:
        - placeholder
        - identifier: portrait
          alternative: 'A portrait placeholder'
          description: 'Shown as the caption'

The short form is the identifier of a declared file. The long form adds the
fields of the file *reference* — :yaml:`alternative`, :yaml:`title`,
:yaml:`description`, :yaml:`link`, :yaml:`crop`: exactly what an editor fills in
on a file relation in the backend. They belong to the reference rather than to
the file, which is what lets the same image carry a different alternative text
in two places.

Referencing a file the set does not declare is refused before anything is
written. A field declared with an empty list creates no reference and leaves the
field alone.

..  _configuration-sites:

Site configurations
===================

..  code-block:: yaml

    sites:
      - identifier: main                    # required, the directory in config/sites/
        rootPage: home                      # required, the identifier of a seeded page
        template: 'Sites/main'              # optional, default Sites/<identifier>
        base: 'https://example.com/'        # optional, overrides the template

A site configuration is written from a **template**: a directory holding a
:file:`config.yaml` and optionally a :file:`settings.yaml`, which is exactly the
shape of a site below :file:`config/sites/`. A template is therefore produced by
copying a working site out of an installation.

..  list-table::
    :header-rows: 1

    *   -   Key
        -   Required
        -   Meaning
    *   -   :yaml:`identifier`
        -   yes
        -   Becomes the directory name below :file:`config/sites/`. Letters,
            digits, dashes and underscores.
    *   -   :yaml:`rootPage`
        -   yes
        -   The **seed** identifier of the page that becomes the site root. It
            has to name a page of the same set.
    *   -   :yaml:`template`
        -   no
        -   The template directory, relative to the set or an :file:`EXT:` path.
    *   -   :yaml:`base`
        -   no
        -   Replaces the ``base`` of the template.

Three rules apply to the result:

*   **The root page always wins.** ``rootPageId`` is taken from the uid the
    declared page was written with, whatever the template says. That is the
    whole reason the set names the page by its seed identifier.
*   **An existing site identifier is refused.** TYPO3 *merges* an incoming site
    configuration into an existing one, so seeding over it would produce neither
    the template nor the previous configuration but a mixture of both. Remove
    the site first if the seed is meant to replace it.
*   **A minimal template needs almost nothing.** ``base`` and ``languages`` are
    worth declaring, because their defaults are a site on ``/`` in
    "Default / en_US.UTF-8". ``dependencies`` — the site sets a site pulls in —
    is written unchanged and works on TYPO3 v13 and v14 alike.

Placeholders such as ``%env(...)%`` inside a template are **not** resolved while
seeding. They are written as they stand, so the installation resolves them every
time it reads the file — which is what they are for.

The site TYPO3 creates by itself
--------------------------------

TYPO3 writes an ``autogenerated-<uid>`` site configuration whenever a new page
becomes a site root. An import suppresses that, always — whether the set
declares :yaml:`sites` or not — because such a configuration is never what a
seed wanted.

The consequence is reported rather than left to be discovered: when a seeded
site root ends up covered by no site configuration at all, the import warns and
names the pages. A page tree without a site is a frontend that cannot render,
and nothing else would say so.

..  _configuration-imports:

Splitting a set over several files
==================================

..  code-block:: yaml

    imports:
      - { resource: Pages.yaml }
      - { resource: 'EXT:my_extension/Configuration/Seeder/shared/Content.yaml' }

Imported lists are **merged** into the importing file rather than replacing it,
resources are resolved relative to the file declaring them, and :file:`EXT:`
paths are accepted. An imported file carries the same keys as the entry file —
except the three metadata keys, which belong into :file:`config.yml`.

A resource that cannot be read stops the import with an error. It is not skipped:
a typo in a path would otherwise mean the pages of that file are silently not
seeded while the import reports success.

Placeholders are **not** substituted in a seed definition, unlike in a site
configuration. Seeded content has to arrive in the database exactly as it was
written, and ``%`` occurs in pairs in perfectly ordinary texts.

..  _configuration-commands:

The commands
============

..  _configuration-command-list:

seeder:list
-----------

..  code-block:: bash

    vendor/bin/typo3 seeder:list
    vendor/bin/typo3 seeder:list -v

Lists identifier, title and providing extension of every set found. :bash:`-v`
adds the directory the set lives in. Sets appear in the order the installation
loads their extensions in, and within one extension sorted by directory name.

The command exits non-zero when an identifier is provided by more than one
extension — the sets cannot be told apart, so neither listing nor importing may
pick one of them — and when a :file:`config.yml` cannot be read or does not name
itself. An installation without any seed set is not an error.

..  _configuration-command-import:

seeder:import
-------------

..  code-block:: bash

    vendor/bin/typo3 seeder:import demo
    vendor/bin/typo3 seeder:import demo --dry-run
    vendor/bin/typo3 seeder:import demo --base='https://example.com/'

Without an identifier the command asks which set to import. Without a terminal
to ask on — a deployment script, a pipeline, a hook — it lists the available
sets and exits non-zero rather than guessing.

..  list-table::
    :header-rows: 1

    *   -   Option
        -   Effect
    *   -   :bash:`--dry-run`
        -   Parse, validate and check the set, report what an import would do,
            and write nothing.
    *   -   :bash:`--force`
        -   Import although the set suggests uids this installation already
            uses. See :ref:`Declared uids <configuration-uids>`.
    *   -   :bash:`--root-page`
        -   The page the set is written below. ``0``, the default, is the page
            tree root. The page has to exist.
    *   -   :bash:`--base`
        -   Replaces the ``base`` of every site configuration the set writes.
            Use it to import one set into several installations.
    *   -   :bash:`--no-site-config`
        -   Skip the site configurations the set declares. The warning about
            uncovered site roots still appears.
    *   -   :bash:`-v`
        -   Add the table of seed identifiers and the uids they were written
            with.

Exit codes
~~~~~~~~~~

..  list-table::
    :header-rows: 1

    *   -   Code
        -   Meaning
    *   -   0
        -   The set was imported, or the dry run found nothing to complain
            about.
    *   -   2
        -   No identifier and no terminal to ask on, or an option value that
            cannot be used.
    *   -   3
        -   No active extension provides a set of that identifier.
    *   -   4
        -   The set cannot be told apart: the identifier is provided more than
            once, or a :file:`config.yml` in this installation cannot be read.
    *   -   5
        -   The set is not a valid seed definition.
    *   -   6
        -   The set suggests uids this installation already uses.
    *   -   7
        -   There is no administrator backend user to write as.
    *   -   8
        -   Writing the set failed.

A dry run does everything a real run does except the writing: it resolves the
set, parses it, builds what would be written and checks the uids. A set that a
dry run accepts fails afterwards only for a reason that lives in the
installation rather than in the set.

..  _configuration-written:

What the seeder writes by itself
================================

..  list-table::
    :header-rows: 1

    *   -   Field
        -   Rule
    *   -   ``pid``
        -   Structure — it comes from the nesting and is never taken from the
            set.
    *   -   ``hidden``
        -   Defaults to visible. TYPO3 creates records hidden, which is right
            for an editor and wrong for a seed. Declare :yaml:`hidden: 1` for a
            record that is meant to be hidden.
    *   -   ``doktype``, ``l10n_parent``, ``sys_language_uid``
        -   Written on every page unless the set declares them, so that a set
            produces the same page in every installation rather than whatever
            the local configuration defaults to.
    *   -   ``sorting``
        -   Computed by TYPO3 so that records appear in the order they are
            declared.
    *   -   The columns of a file reference
        -   ``uid_local``, ``uid_foreign``, ``tablenames``, ``fieldname`` and
            ``pid`` are structure and always win over a declared value.

Everything else in a record comes from the set, or from the TCA of the
installation where the set declares nothing.

See also
========

*   :ref:`Introduction <introduction>`
*   :ref:`Installation <installation>`
*   :ref:`Changelog <changelog>`
