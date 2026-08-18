..  include:: /Includes.rst.txt

..  _feature-seed-definition-format:

===============================
Feature: Seed definition format
===============================

Description
===========

A **seed set** describes the content of a TYPO3 installation in YAML. It is a
directory :file:`Configuration/Seeder/<name>/` inside any active extension, with
:file:`config.yml` as its entry file:

..  code-block:: yaml
    :caption: packages/my_extension/Configuration/Seeder/demo/config.yml

    identifier: demo
    title: 'Demo page tree'

    pages:
      - identifier: home
        uid: 1
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
            title: 'About'
            slug: '/about'

Every key that is not structural is written to the record as a field, so a table
needs no support in the extension to be seedable. The structural keys are
:yaml:`identifier`, :yaml:`uid`, :yaml:`children`, :yaml:`content`,
:yaml:`records`, :yaml:`inline` and :yaml:`files` — with :yaml:`table` and
:yaml:`records` being structural only where they cannot be a column of the table
in question.

Further properties of the format:

*   :yaml:`children`, :yaml:`content` and :yaml:`records` nest records onto the
    declaring page, and records come out in the order they were declared.
*   :yaml:`inline` nests records into a relation instead, as a map of the parent
    field to the child records.
*   A declared :yaml:`uid` makes a seeded page tree reproducible. It is checked
    against the installation before anything is written, and an import that
    would collide is refused with the records that are in the way.
*   :yaml:`imports` splits a set over several files, merging the imported lists
    into the importing file. A resource that cannot be read fails the import
    rather than being skipped.
*   An unknown key at set level or in a site declaration is refused, because
    there it can only be a mistake.

Impact
======

Extensions can ship the content an installation starts from, and that content is
reviewable, versioned and reproducible like any other part of the repository.

The complete reference is in the :ref:`Configuration <configuration>` chapter.
