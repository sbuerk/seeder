# PHPUnit configuration

## Files and their provenance

| File                                                                                             | Purpose                                        |
|--------------------------------------------------------------------------------------------------|------------------------------------------------|
| [`Build/phpunit/UnitTests.xml`](../../Build/phpunit/UnitTests.xml)                               | PHPUnit configuration of the unit suite.       |
| [`Build/phpunit/UnitTestsBootstrap.php`](../../Build/phpunit/UnitTestsBootstrap.php)             | Bootstrap referenced by `UnitTests.xml`.       |
| [`Build/phpunit/FunctionalTests.xml`](../../Build/phpunit/FunctionalTests.xml)                   | PHPUnit configuration of the functional suite. |
| [`Build/phpunit/FunctionalTestsBootstrap.php`](../../Build/phpunit/FunctionalTestsBootstrap.php) | Bootstrap referenced by `FunctionalTests.xml`. |

All four are **copies** of the boilerplate maintained in
`typo3/testing-framework` at
`Resources/Core/Build/`. The framework explicitly asks extensions not to use
those files directly but to copy them, because an extension needs different test
suite paths and usually additional bootstrap code.

They are currently baselined from **testing-framework 8.3.3** — the 8.x line,
which is the one covering TYPO3 v12 and v13 — and each file records that
version in its own header comment together with its deviations. The two
bootstraps additionally record that the 8.3.3 boilerplate is byte identical to
the 9.6.1 one, so the branch supporting v13 and v14 carries the same two files.

Being copies, they do not update themselves. When `typo3/testing-framework` is
raised to a new version, diff the four files against the new template, adopt
what changed and update the recorded baseline version:

```bash
diff -u .Build/vendor/typo3/testing-framework/Resources/Core/Build/FunctionalTests.xml \
        Build/phpunit/FunctionalTests.xml
```

## Deliberate deviations from the template

Everything that differs from the upstream boilerplate is intentional and listed
here. Anything not on this list is drift and should be reconciled.

| Deviation                                                                 | Reason                                                                                                                                                                                                                     |
|---------------------------------------------------------------------------|----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `<directory>../../Tests/Unit/</directory>` and `../../Tests/Functional/`  | The template points at the TYPO3 mono-repository system extension paths.                                                                                                                                                   |
| Schema location `../../.Build/vendor/phpunit/phpunit/phpunit.xsd`         | Resolves against the installed PHPUnit instead of a remote URL, so validation works offline and always matches the installed version. The template names the 10.1 schema, which predates three of the attributes set here. |
| Additional `failOn*`, `beStrictAbout*` and `displayDetailsOn*` attributes | The strictness policy below. The template stops at the defaults of a core test run.                                                                                                                                        |
| Imports and `: void` in the bootstraps                                    | Coding guidelines of this repository (`cgl` gate).                                                                                                                                                                         |
| `AvailableFixturePackages` adoption in `FunctionalTestsBootstrap.php`     | Makes fixture extensions loadable by composer package name — see [Fixture extensions](fixture-extensions.md).                                                                                                              |
| The request type is passed unconditionally in `UnitTestsBootstrap.php`    | The template's `class_exists(CoreHttpApplication::class)` branch distinguishes nothing, and the two request types are indistinguishable in effect on v12. See below.                                                       |

### The request type branch that is not reproduced

The boilerplate calls `SystemEnvironmentBuilder::run()` with
`REQUESTTYPE_BE|REQUESTTYPE_CLI` where `CoreHttpApplication` is absent — meant to
be TYPO3 v12 — and with `REQUESTTYPE_CLI` otherwise. This repository always
passes `REQUESTTYPE_CLI`, for two reasons that were read rather than assumed:

- The boilerplate declares **no namespace and no import** for
  `CoreHttpApplication`, so the name resolves to the global
  `\CoreHttpApplication`, `class_exists()` is false on every core version, and
  upstream in fact always takes its else branch. The guard separates nothing.
- On v12 the two values are indistinguishable in effect.
  `Core\Core\SystemEnvironmentBuilder::run()` hands `$requestType` to
  `calculateScriptPath()`, `calculateRootPath()` and `initializeEnvironment()`
  only, and each of the three consumes it exclusively through
  `isCliRequestType()`, which tests `($requestType & REQUESTTYPE_CLI)` and is
  true for both. v12 no longer defines a `TYPO3_REQUESTTYPE` constant, so nothing
  else can observe the difference either.

The full reasoning is in the header comment of
[`UnitTestsBootstrap.php`](../../Build/phpunit/UnitTestsBootstrap.php) itself.

## Strictness policy

The suites are configured to be **as hard breaking as possible**. A notice,
warning or deprecation triggered by the code under test is a defect, and the
suite must surface it as a failure rather than print it and continue.

| Setting                                   | Unit             | Functional       | Effect                                                               |
|-------------------------------------------|------------------|------------------|----------------------------------------------------------------------|
| `failOnDeprecation`                       | `true`           | `true`           | A PHP or user deprecation fails the run.                             |
| `failOnNotice`                            | `true`           | `true`           | A notice fails the run.                                              |
| `failOnWarning`                           | `true`           | `true`           | A warning fails the run.                                             |
| `failOnPhpunitDeprecation`                | `true`           | `true`           | Use of a deprecated PHPUnit API fails the run.                       |
| `failOnPhpunitWarning`                    | `true`           | `true`           | A PHPUnit warning fails the run. Pinned rather than left to default. |
| `failOnRisky`                             | `true`           | `true`           | A risky test fails the run.                                          |
| `failOnIncomplete`                        | `true`           | `true`           | `markTestIncomplete()` is not a way to park work.                    |
| `failOnEmptyTestSuite`                    | `true`           | `true`           | Catches a mistyped `--filter` reporting green on zero tests.         |
| `beStrictAboutTestsThatDoNotTestAnything` | default (`true`) | default (`true`) | A test without an assertion is risky, therefore a failure.           |
| `beStrictAboutOutputDuringTests`          | `true`           | `true`           | Stray `echo`/`var_dump()` is risky, therefore a failure.             |
| `beStrictAboutChangesToGlobalState`       | `true`           | *off*            | See the note below.                                                  |
| `backupGlobals`                           | `true`           | `true`           | Globals are restored after each test.                                |
| `failOnSkipped`                           | *off*            | *off*            | See the note below.                                                  |
| `displayDetailsOn*`                       | `true`           | `true`           | Issues, skipped and incomplete tests are reported with details.      |

Four consequences worth knowing:

- **`backupGlobals="true"`** means a test may modify `$GLOBALS` without leaking
  into the next test. It is also what makes `failOnRisky` usable at all in a
  TYPO3 context, where `$GLOBALS['TYPO3_CONF_VARS']` is touched constantly.
- **A test without an assertion fails.** The template ships
  `beStrictAboutTestsThatDoNotTestAnything="false"`; that override is removed
  here. A test that asserts nothing proves nothing. Where a test genuinely has
  no return value to check — "this call does not throw" — assert that
  explicitly, for example with `self::expectNotToPerformAssertions()` or by
  asserting on the resulting state.
- **`beStrictAboutChangesToGlobalState` is off for functional tests.** Every
  functional test runs against its own TYPO3 instance, and building that
  instance writes `$GLOBALS['TCA']`, `$GLOBALS['TYPO3_CONF_VARS']` and parts of
  `$_SERVER`. With the check enabled, every functional test is reported as
  risky. The unit suite has no such instance and keeps it enabled.
- **A skipped test is not a failure.** Skipping is the mechanism the core
  version split relies on (`--exclude-group not-core-<version>`), so failing on
  skipped tests would break the dual core setup. Use groups, not
  `markTestSkipped()`, to exclude a test from a core version — see
  [Dual core setup](../development/dual-core-setup.md#test-grouping).

`failOnAllIssues` would cover most of the table with a single attribute, but it
implies `failOnSkipped`, so the flags are listed individually instead.

The `error_reporting` PHP setting is deliberately **not** pinned in the
configuration. PHPUnit installs its own error handler, which is invoked for
every diagnostic regardless of the ambient level; the level is only read to
detect `@` suppression. Setting it would not raise strictness and would
interfere with that detection.

## See also

- [Unit tests](unit-tests.md)
- [Functional tests](functional-tests.md)
- [Quality gates](../development/quality-gates.md)
