..  include:: /Includes.rst.txt

..  _feature-typo3-v14-support:

==========================
Feature: TYPO3 v14 support
==========================

Description
===========

Version 2.0 runs on TYPO3 **v14.3** alongside v13.4, from one code base, on PHP
8.2 up to 8.5.

Nothing about a seed set changes. The set descriptor, the scenario format, the
two commands and every one of their exit codes are the same on both core
versions, so a set written for v13.4 imports unchanged on v14.3.

Impact
======

Where an implementation has to differ between the two core versions, the classes
are split per version - below :file:`Core13/` and :file:`Core14/` - and the
dependency injection container registers the ones matching the running
installation. None of that is visible in a seed set, or to anything calling the
commands.
