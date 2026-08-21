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

No part of the implementation differs between the two core versions: v13.4 and
v14.3 run the same classes. The mechanism for a difference is in place - a
:file:`Core13/` and a :file:`Core14/` directory, of which the dependency
injection container registers only the one matching the running installation -
and both are currently empty. None of that is visible in a seed set, or to
anything calling the commands.
