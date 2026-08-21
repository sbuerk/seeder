..  include:: /Includes.rst.txt

..  _feature-initial-seeder:

===================================
Feature: Initial extension skeleton
===================================

Description
===========

Initial skeleton of the ``sbuerk/seeder`` extension, providing the
project setup the actual implementation is built on:

*   TYPO3 v12.4 and v13.4 support on PHP 8.1 up to 8.4 - PHP 8.1 for TYPO3 v12
    only - with core version aware implementations below :file:`Core12/` and
    :file:`Core13/`.
*   Dependency injection wiring through :file:`Configuration/Services.php`,
    with services configured by Symfony dependency injection attributes on the
    classes themselves.
*   Container based tooling through :file:`Build/Scripts/runTests.sh` covering
    linting, coding guidelines, static analysis, unit and functional tests and
    documentation rendering.
*   GitHub Actions workflows running these gates for TYPO3 v12 and v13 on pull
    requests.
*   A functional test setup ready to build on: strict PHPUnit configuration,
    an example fixture extension loaded by its composer package name, site
    based tests issuing frontend sub-requests in several languages, and
    repository tests running in a built frontend environment.
*   Developer documentation below :file:`docs/`, covering the architecture,
    the quality gates, both test suites and the release workflow.
