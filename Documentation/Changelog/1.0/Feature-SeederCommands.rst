..  include:: /Includes.rst.txt

..  _feature-seeder-commands:

=========================================================
Feature: The "seeder:list" and "seeder:import" commands
=========================================================

Description
===========

Two console commands make the seed sets of an installation visible and import
one of them.

..  code-block:: bash

    vendor/bin/typo3 seeder:list
    vendor/bin/typo3 seeder:import demo --dry-run
    vendor/bin/typo3 seeder:import demo --base='https://example.com/'

:bash:`seeder:list` prints identifier, title and providing extension of every
seed set found in :file:`Configuration/Seeder/*/config.yml` of an active
extension, in the order the installation loads its extensions in. :bash:`-v`
adds the directory of each set. An identifier provided by more than one
extension is reported with all of its providers and makes the command exit
non-zero: the sets cannot be told apart, so neither listing nor importing may
pick one of them.

:bash:`seeder:import` writes one set. Without an identifier it asks which one;
without a terminal to ask on it lists the available sets and exits non-zero
rather than guessing. The options are:

..  list-table::
    :header-rows: 1

    *   -   Option
        -   Effect
    *   -   :bash:`--dry-run`
        -   Validate the set and report what an import would do, writing
            nothing.
    *   -   :bash:`--force`
        -   Import although the set suggests uids this installation already
            uses.
    *   -   :bash:`--root-page`
        -   The page the set is written below. ``0`` is the page tree root.
    *   -   :bash:`--base`
        -   Replaces the ``base`` of every site configuration the set writes.
    *   -   :bash:`--no-site-config`
        -   Skip the site configurations the set declares.

Every failure has an exit code of its own — unknown set, ambiguous set, invalid
definition, uid collision, no administrator, failed write — so that a deployment
script can tell "no such set" from "that would overwrite something" without
parsing the output.

Impact
======

An installation can be provisioned from the command line, and a pipeline can act
on what happened. The complete option and exit code reference is in the
:ref:`Configuration <configuration>` chapter.
