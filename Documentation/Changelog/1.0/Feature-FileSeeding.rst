..  include:: /Includes.rst.txt

..  _feature-file-seeding:

======================================
Feature: Seeding files and references
======================================

Description
===========

A seed set brings the files its records need, and attaches them as file
references:

..  code-block:: yaml

    files:
      - identifier: placeholder
        source: 'Files/placeholder.svg'
        folder: 'demo'

    pages:
      - identifier: home
        title: 'Demo'
        content:
          - identifier: teaser
            CType: textmedia
            header: 'With an image'
            files:
              assets:
                - identifier: placeholder
                  alternative: 'A placeholder graphic'
                  description: 'Shown as the caption'

Files are copied before the records are written, and through the file storage
API rather than through the file system — which is what indexes them, so the
copied file exists for TYPO3 and not only on disk. A missing target folder is
created, an existing file of the same name is replaced, the source in the
extension is left where it is, and a set may name the storage to write into.

A reference is either the bare identifier of a declared file or a map adding the
fields of the reference record: :yaml:`alternative`, :yaml:`title`,
:yaml:`description`, :yaml:`link` and :yaml:`crop` — the fields an editor fills
in on a file relation. They belong to the reference and not to the file, so the
same image can carry a different alternative text in two places.

References of one field keep the order they were declared in. Referencing a file
the set does not declare is refused before anything is written.

Impact
======

A seed set is complete: it ships the images of the content it describes, instead
of describing content that points at files someone has to upload first.
