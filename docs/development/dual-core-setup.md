# Dual core setup

This extension supports **TYPO3 v12.4 and v13.4** from one code base, and the
tooling installs **one** dependency set into `.Build/` at a time. Everything
below follows from that: the two versions are developed against alternately,
never simultaneously, and the whole development workflow rests on knowing which
one is currently installed.

## The rule

> **The dependency set installed in `.Build/` must match the core version the
> tool is run for.**

`-t <version>` selects the core version for a suite, but it does **not** install
anything. Only `composerUpdate` changes what is in `.Build/`:

```bash
# 1. Install the dependency set ...
Build/Scripts/runTests.sh -t 12 -s composerUpdate

# 2. ... then run gates with the SAME -t value.
Build/Scripts/runTests.sh -t 12 -s phpstan
Build/Scripts/runTests.sh -t 12 -s unit
Build/Scripts/runTests.sh -t 12 -s functional -d sqlite
```

Running a suite with a `-t` value other than the installed set produces **false
positives and false negatives**, not an error message. PHPStan reports missing
classes that exist in the other core version, and tests pass or fail for reasons
unrelated to the change under test. `runTests.sh` rejects an unsupported `-t`
value outright, but it cannot see what is installed — that part is on the person
running it.

**Do one version completely, then switch.** Interleaving `-t 12` and `-t 13`
commands is the reliable way to produce a green run that proves nothing.

`composerInstall` does **not** honour `-t`; it only replays the current
`composer.lock`. `composerUpdate` removes and reinstalls `.Build/` and
`composer.lock` — both are git-ignored, so nothing of value is lost.

Locally it drops the composer download cache in `.cache/` as well, which is why
a switch downloads the dependency set rather than unpacking it. That is a
deliberate precaution: a working copy accumulates installs across core versions,
and switching between them exchanges the **major version** of four packages at
once — see the table below.
→ [The composer cache](quality-gates.md#the-composer-cache)

## The PHP dimension is not square

The two core versions do not accept the same PHP versions, so `-t` and `-p` are
not independent:

| `-p`  | TYPO3 v12.4 | TYPO3 v13.4 |
|-------|-------------|-------------|
| `8.1` | yes         | **no**      |
| `8.2` | yes         | yes         |
| `8.3` | yes         | yes         |
| `8.4` | yes         | yes         |

`typo3/cms-core` 13.4 requires PHP `^8.2`, so `-t 13 -p 8.1` cannot resolve at
all. `runTests.sh` does **not** cross-validate the pair — `composerUpdate`
refuses it by failing to resolve, which is the honest place for it. The `-p`
help text says so.

The default is `-p 8.2`, the lowest version valid for **both** core versions,
and the default of `-t` is **12**, the lowest supported core version: gates that
do not depend on a core version are run against the lowest set, which is where
an accidentally used newer API shows up.

PHP 8.1 is therefore only ever exercised together with `-t 12`. That combination
is not optional decoration — it is what catches PHP 8.2-only syntax such as a
`readonly` class or a constant in a trait, both of which parse on every other
supported version.
→ [Class design](../architecture/class-design.md#the-php-81-rule-readonly-sits-on-the-properties)

> [!CAUTION]
> **`composerUpdate` pins the PHP platform requirement of the installed set.**
> A dependency set installed with `-p 8.2` writes that platform requirement into
> the autoloader, and `composer/platform_check.php` then refuses to run it under
> PHP 8.1 — the run aborts before a single test method is reached. Verifying the
> PHP 8.1 leg locally therefore needs its **own** install:
>
> ```bash
> Build/Scripts/runTests.sh -t 12 -p 8.1 -s composerUpdate
> Build/Scripts/runTests.sh -t 12 -p 8.1 -s lintPhp
> Build/Scripts/runTests.sh -t 12 -p 8.1 -s unit
> ```
>
> Passing `-p 8.1` to the gate alone, on top of a set installed for 8.2, does
> not exercise PHP 8.1 — it fails on the platform check instead.

## The dependency sets differ by more than the core

Switching `-t` does not only exchange `typo3/cms-core`. The v12 set resolves an
older toolchain, because the newer one does not support v12 at all. The versions
below are what an install actually resolved, not what the constraints permit:

| Package                              | With `-t 12` | With `-t 13` |
|--------------------------------------|--------------|--------------|
| `typo3/cms-core`                     | 12.4.45      | 13.4.x       |
| `typo3/testing-framework`            | 8.3.3        | 8.3.3        |
| `phpunit/phpunit`                    | 10.5.64      | 10.5.64      |
| `phpstan/phpstan`                    | 1.12.34      | 2.2.x        |
| `phpstan/phpstan-phpunit`            | 1.4.2        | 2.0.x        |
| `saschaegerer/phpstan-typo3`         | 1.10.2       | 2.1.x        |
| `nikic/php-parser`                   | 4.19.5       | 5.x          |
| `sbuerk/typo3-site-based-test-trait` | 1.0.2        | 2.0.1        |
| `fgtclb/environment-state-manager`   | 1.0.0        | 1.0.0        |

Three of those cost real time to work out and are worth writing down:

- **`saschaegerer/phpstan-typo3` is what pins PHPStan to 1.x on v12.** Its 2.x
  and 3.x releases require PHP `^8.2`, so the PHP 8.1 leg cannot take them and
  has to stay on 1.10.2 — which in turn requires PHPStan 1.x. The v13 leg keeps
  the 2.x toolchain regardless. That is why `Build/phpstan/Core12/` and
  `Build/phpstan/Core13/` are not merely two paths but two PHPStan majors, with
  their own baselines and their own rule set.
- **PHPUnit is pinned to the single `^10.5.64` line for the whole branch**,
  rather than left as a range. `typo3/testing-framework` 8.x permits PHPUnit 10
  *or* 11, and PHPUnit 11 requires PHP ≥ 8.2, so an unpinned constraint would
  resolve 11.5 everywhere except the PHP 8.1 job and run two PHPUnit majors on
  one branch. The two disagree on runner semantics — most visibly on
  `--exclude-group`, which 10.5 accepts only once, with a comma separated list,
  and 11 wants repeated. One major means one spelling.
  → [PHPUnit configuration](../testing/phpunit-configuration.md)
- **`nikic/php-parser` spans two majors**, because `typo3/cms-install` requires
  `^4.15.4` on v12. `Build/Scripts/testMethodPrefixChecker.php` is its only
  consumer here and picks its `ParserFactory` spelling at runtime —
  `ParserFactory::createForVersion()` and `PhpParser\PhpVersion` are 5-only. A
  build script is not extension code, so a conditional is the right tool there,
  and it carries a `@todo`.

`fgtclb/environment-state-manager` 1.0.0 is the v12-capable major and is what
raises the core floor to **12.4.22**; the `typo3/cms-core` constraint states that
floor as `^12.4.22 || ^13.4` rather than hiding it behind `^12.4`.

## The changelogs come with the dependency set

The TYPO3 changelogs live inside the core package, so what is readable below
`.Build/vendor/typo3/cms-core/Documentation/Changelog/` is decided by which set
is installed. A package carries the changelogs of its own and all **earlier**
versions:

| Installed set | On disk                     | Missing        |
|---------------|-----------------------------|----------------|
| `-t 12`       | `7.0/` … `12.4/`, `12.4.x/` | everything v13 |
| `-t 13`       | `7.0/` … `13.4/`, `13.4.x/` | —              |

Installing the **highest** supported version therefore puts both sets on disk at
once and saves switching back and forth to look something up. Reading a changelog
is not running a gate — look it up with the v13 set installed, then
`composerUpdate` back to the version being worked on before running anything.
→ [Referencing TYPO3 behaviour changes](../workflow/commit-messages.md#referencing-typo3-behaviour-changes)

## Verifying a change

A change is only verified when the full sequence has run for **both** core
versions, each after its own `composerUpdate`:

```bash
for core in 12 13; do
    Build/Scripts/runTests.sh -t "$core" -s composerUpdate
    Build/Scripts/runTests.sh -t "$core" -s cgl -n
    Build/Scripts/runTests.sh -t "$core" -s phpstan
    Build/Scripts/runTests.sh -t "$core" -s lintPhp
    Build/Scripts/runTests.sh -t "$core" -s unit
    Build/Scripts/runTests.sh -t "$core" -s unitRandom
    Build/Scripts/runTests.sh -t "$core" -s functional -d sqlite
    Build/Scripts/runTests.sh -t "$core" -s composerValidate
    Build/Scripts/runTests.sh -t "$core" -s checkBom
    Build/Scripts/runTests.sh -t "$core" -s checkExceptionCodes
    Build/Scripts/runTests.sh -t "$core" -s checkMarkdownTables
    Build/Scripts/runTests.sh -t "$core" -s checkTestMethodsPrefix
done
```

Then the PHP 8.1 leg, which the loop above never reaches — it needs an install of
its own, see the caution above:

```bash
Build/Scripts/runTests.sh -t 12 -p 8.1 -s composerUpdate
Build/Scripts/runTests.sh -t 12 -p 8.1 -s lintPhp
Build/Scripts/runTests.sh -t 12 -p 8.1 -s unit
Build/Scripts/runTests.sh -t 12 -p 8.1 -s functional -d sqlite
```

`lintPhp` is the cheap one and the one that must not be skipped when a change
adds or moves a class: it is the only run in which PHP 8.1 *syntax* is checked at
all, and a `readonly class` that slipped in parses everywhere else.

Add `-s functional -d mariadb -i 10.6` (or `mysql`, `postgres`) when the change
touches queries, schema or TCA — the schema is derived from TCA, so a TCA change
is a schema change, and the two core versions
[do not derive the same columns](../architecture/core-version-aware-code.md#configuration-is-the-exception).

Leaving the working copy on one core version and only running the other in CI is
not enough — CI reports the failure *after* the pull request is open, and the
core version aware code is exactly where mistakes happen.

## What is core version dependent

| Artefact                        | Per core version?                                                  |
|---------------------------------|--------------------------------------------------------------------|
| `Classes/`                      | No — must work on every supported version.                         |
| `Core<major>/`                  | Yes — `Core12/` and `Core13/`.                                     |
| `Build/phpstan/Core<major>/`    | Yes — separate config and baseline each, and two PHPStan majors.   |
| `Tests/Unit/Core<major>/`       | Yes — grouped, see below.                                          |
| `Tests/Functional/Core<major>/` | Yes — same grouping.                                               |
| `Build/phpunit/*.xml`           | No — one configuration for both.                                   |
| `Configuration/`                | No — loaded from a fixed path, so a difference is applied in-file. |
| `.github/workflows/ci.yml`      | No — the core version is a matrix dimension.                       |

The mechanism is described in full in
[Core version aware code](../architecture/core-version-aware-code.md).

## Test grouping

`Build/Scripts/runTests.sh` passes `--exclude-group not-core-<version>` to
PHPUnit for whichever core version `-t` selected. A test that must **not** run on
a given core version therefore declares the group naming that version:

```php
#[Group('not-core-13')]
final class SeedWriterTest extends UnitTestCase
{
}
```

Note the inverted logic: the group names the core version the test must **not**
run on, so a test without any group runs everywhere.

The functional suite passes **one** `--exclude-group` carrying a comma separated
list — `not-<dbms>,not-core-<version>` — and never two of them. This branch pins
PHPUnit to the 10.5 line, which rejects a repeated option with *"Option
--exclude-group cannot be used more than once"*; that arrives as a runner
warning, and `failOnPhpunitWarning` turns a warning into a failed run. The wrong
spelling therefore reports FAILURE on a fully passing suite.

## See also

- [Development environment](environment.md)
- [Quality gates](quality-gates.md)
- [Core version aware code](../architecture/core-version-aware-code.md)
- [Class design](../architecture/class-design.md)
