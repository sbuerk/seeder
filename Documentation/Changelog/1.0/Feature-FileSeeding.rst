..  include:: /Includes.rst.txt

..  _feature-file-seeding:

======================
Feature: Seeding files
======================

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

..  note::

    This version seeds files, and it does not attach them to records: there is
    no way yet to declare a :sql:`sys_file_reference` from a scenario. A
    relation to a file therefore has to be written the way any other relation is
    written in the scenario format, by uid.

Impact
======

A seed set ships the images of the content it describes, instead of describing
content that points at files someone has to upload first.
