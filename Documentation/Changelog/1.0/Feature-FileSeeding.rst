..  include:: /Includes.rst.txt

..  _feature-file-seeding:

==========================================
Feature: Seeding files and file references
==========================================

Description
===========

A seed set brings the files its content needs, and copies them into a file
storage before the first record is written:

..  code-block:: yaml
    :caption: packages/my_extension/Configuration/Seeder/demo/config.yml

    files:
      - identifier: placeholder
        source: 'Files/placeholder.svg'
        folder: 'demo'
        name: 'placeholder.svg'
        storage: 1

:yaml:`identifier` is unique among the files of the set, :yaml:`source` is a
path relative to the directory holding the set or an :file:`EXT:` path,
:yaml:`folder` defaults to the storage root, :yaml:`name` to the base name of
the source, and :yaml:`storage` to the default storage of the installation.
Those five keys are the whole vocabulary of a file: an unknown key is refused,
naming the known ones.

The copy goes through the file storage API rather than through the file system -
which is what indexes the file, so the result exists for TYPO3 and not only on
disk. A missing target folder is created, an existing file of the same name is
replaced, and the source in the extension is left where it is.

Files are written in a pass of their own, before the records, so that a record
naming a :sql:`sys_file` uid names one that exists.

Attaching a file to a record
============================

A seeded file is attached to a seeded record through :yaml:`references`, the
second key of :file:`config.yml` this feature adds:

..  code-block:: yaml
    :caption: packages/my_extension/Configuration/Seeder/demo/config.yml

    references:
      - file: placeholder
        table: tt_content
        uid: 2000
        field: assets
        values: {title: 'A placeholder', alternative: 'Nothing to see here'}

:yaml:`file` names one of the :yaml:`files` of the same set, :yaml:`table` and
:yaml:`uid` name the record the reference hangs on, :yaml:`field` is the file
relation column of that record, and :yaml:`values` are the fields of the
:sql:`sys_file_reference` row itself - the ones an editor fills in on a file
relation. Those five keys are the whole vocabulary of a reference; an unknown
key is refused, naming the known ones.

A scenario record has no symbolic name, so :yaml:`uid` is the :yaml:`id` an
entity of the scenario declares - the same rule :yaml:`rootPage` follows - and
it is resolved against what the run actually wrote. A reference naming a record
no entity declares is refused before anything is written, rather than after the
page tree, the content and the files are already in the database.

The structural columns of the row - :sql:`uid_local`, :sql:`uid_foreign`,
:sql:`tablenames`, :sql:`fieldname` and :sql:`pid` - are written by the seeder
and win over a value declared for them, because a definition may not detach a
reference from the record it declares it on. Several references on one field
come out in the order they are declared, and that order is what
:sql:`sorting_foreign` is written from.

References are written through :php:`DataHandler` in a pass of their own, after
the records, so the relation reaches the reference index the way an editor's
would.

Why it is a key of the descriptor rather than of a scenario
===========================================================

Because the scenario format is not this extension's to extend, and because a
:sql:`sys_file_reference` cannot be expressed in it anyway: it points at its
file through :sql:`uid_local`, and that uid is handed out by the FAL indexer
while the file is being placed. A set author cannot write it down in advance,
so the reference is declared where the file is declared - in
:file:`config.yml`.

A relation between two *records* needs no key at all, and does not get one. The
parent writes the declared ids of its children into its relation field, and
:php:`DataHandler` resolves that list like any other relation - see
:ref:`Relations between records <configuration-inline-relations>`.

Impact
======

A seed set ships the images of the content it describes, and the content comes
out with those images attached - instead of describing content that points at
files someone has to upload and hook up first.
