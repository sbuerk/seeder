..  include:: /Includes.rst.txt

..  _breaking-typo3-v12-support-removed:

===================================
Breaking: TYPO3 v12 support removed
===================================

Description
===========

Version 2.0 supports TYPO3 **v13.4 and v14.3** on PHP 8.2 up to 8.5. TYPO3
v12.4 - and PHP 8.1, which only ever applied to v12 - is carried by the **1.x**
line from the ``1`` branch, and no longer by this one.

That is what the two lines are for: 1.x carries TYPO3 v12.4.22 up to v13.4, 2.x
carries v13.4 up to v14.3, and v13.4 is the version both of them serve. An
installation on v13.4 moves between the lines without changing anything about
its seed sets - the set descriptor, the scenario format, the two commands and
their exit codes are identical.

Impact
======

An installation on TYPO3 v12.4 stays on the 1.x line:

..  code-block:: bash

    composer require --dev "sbuerk/data-factory:^1.0"

Nothing breaks silently: :file:`composer.json` requires ``typo3/cms-core``
``^13.4 || ^14.3`` and :file:`ext_emconf.php` constrains the extension to
``13.4.0-14.3.99``, so 2.x is refused on TYPO3 v12.4 by the dependency resolver
in composer mode and by the extension manager in classic mode, rather than
being installed and failing at runtime.

The complete matrix of both lines is in :ref:`Compatibility <compatibility>`.
