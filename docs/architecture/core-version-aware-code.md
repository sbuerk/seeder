# Core version aware code

The extension supports more than one TYPO3 major version from a single code
base. Code that cannot be written for all of them at once is **core version
aware**: it exists once per supported core version, and only the variant
matching the running TYPO3 version is used.

That structure does not depend on how many versions there happen to be. One
`Core<major>/` directory exists per supported core version, and today there are
two of them.

## Where the code lives

| Directory  | Contains                                                                                                                |
|------------|-------------------------------------------------------------------------------------------------------------------------|
| `Classes/` | Everything working on **all** supported core versions: interfaces, abstract base classes, version independent services. |
| `Core12/`  | Implementations for TYPO3 v12 only.                                                                                     |
| `Core13/`  | Implementations for TYPO3 v13 only.                                                                                     |

`Core12/` and `Core13/` are separate PSR-4 roots in the repository root, not
subdirectories of `Classes/`, and are registered in `composer.json` with the
core version as the third namespace part — one entry per supported core version:

```json
"autoload": {
    "psr-4": {
        "SBUERK\\DataFactory\\": "Classes/",
        "SBUERK\\DataFactory\\Core12\\": "Core12/",
        "SBUERK\\DataFactory\\Core13\\": "Core13/"
    }
}
```

## How the right variant is selected

Composer autoloads **all** of those directories — that is unavoidable and
harmless, as long as a class is never *instantiated* on the wrong core version.
The selection therefore happens in the dependency injection container:
[`Configuration/Services.php`](../../Configuration/Services.php) loads
`Classes/` unconditionally and, on top of it, only the `Core<major>/` directory
matching the running TYPO3 version:

```php
$coreMajorVersion = (new Typo3Version())->getMajorVersion();
$coreAwareDirectory = sprintf('%s/../Core%d', __DIR__, $coreMajorVersion);
if (is_dir($coreAwareDirectory)) {
    $services->load(
        sprintf('SBUERK\\DataFactory\\Core%d\\', $coreMajorVersion),
        $coreAwareDirectory . '/*',
    );
}
```

Because of that, a class below `Core13/` may freely use API that only exists in
TYPO3 v13 — it is never registered, and therefore never instantiated, when the
running core is a different major. The same holds in the other direction: a
class below `Core12/` may use API that v13 removed or deprecated.

This is deliberately the *only* mechanism used for version differences. Do not
write conditional code (`if ($coreMajorVersion === 13) { … }`) in shared classes
below `Classes/`; split the class instead. Shared code stays readable, and each
version aware implementation can be deleted as a whole once its core version is
dropped.

Both directories are tracked with a `.gitkeep` in addition to their sources:
git does not track an empty directory, and `Build/phpstan/Core12/phpstan.neon`,
`Build/phpstan/Core13/phpstan.neon` and `Build/php-cs-fixer/config.php` name
them as analysed paths — a missing one aborts those gates. The `is_dir()` guard
and the glob keep the load itself harmless: neither a missing nor an empty
directory registers anything, and neither is an error.

## The pattern to follow

1. Declare an **interface** in `Classes/` — consumers only ever type hint this.
2. Put shared behaviour into an **abstract base class** in `Classes/`. Abstract
   classes use method injection, see
   [Class design](class-design.md#abstract-classes-must-not-use-constructor-injection).
   An implementation pair that shares nothing but its signature needs no base
   class at all; the interface alone is the seam.
3. Implement it once per core version in `Core12/` and `Core13/`, each
   registering itself as the default implementation of the interface with
   `#[AsAlias]`.

The three files of that pattern, sketched with a fictional subject — there is no
`SeedWriter` in this repository, the names only make the shape readable:

```php
// Classes/Seed/SeedWriterInterface.php
namespace SBUERK\DataFactory\Seed;

interface SeedWriterInterface
{
    public function write(string $table): int;
}
```

```php
// Classes/Seed/AbstractSeedWriter.php
namespace SBUERK\DataFactory\Seed;

abstract class AbstractSeedWriter implements SeedWriterInterface
{
    // Shared behaviour, and #[Required] inject*() methods for its dependencies.
}
```

```php
// Core12/Seed/SeedWriter.php — Core13/Seed/SeedWriter.php is its counterpart
namespace SBUERK\DataFactory\Core12\Seed;

use SBUERK\DataFactory\Seed\AbstractSeedWriter;
use SBUERK\DataFactory\Seed\SeedWriterInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(id: SeedWriterInterface::class, public: true)]
final class SeedWriter extends AbstractSeedWriter
{
    public function write(string $table): int
    {
        // May use API that exists on TYPO3 v12 only.
    }
}
```

Only the interface is ever type hinted. `#[AsAlias]` is what makes the two
implementations interchangeable: both claim the same service id, and only one of
them is ever registered, so the container hands out the one matching the running
core version without a consumer knowing that there are two.

`public: true` is needed here because functional tests fetch the service from
the container to verify exactly that wiring. Services that nothing fetches
directly stay private — see
[Dependency injection](dependency-injection.md#rules).

> [!IMPORTANT]
> The classes above are `final` and `abstract`, **not** `final readonly` and
> `abstract readonly`. This branch supports PHP 8.1 for the TYPO3 v12 leg, where
> a `readonly` class does not parse, so the keyword sits on the properties
> instead. That is a property of the branch, not of the version aware layout.
> → [Class design](class-design.md#the-php-81-rule-readonly-sits-on-the-properties)

### The checklist for a real one

The sketch above is three files; a real addition touches more, and nothing in
the container configuration is one of them. In order:

1. `Classes/<Area>/<Thing>Interface.php` — the seam. This is the only one of the
   three that is ever type hinted.
2. `Classes/<Area>/Abstract<Thing>.php` — **only if** the two implementations
   genuinely share behaviour. A pair that shares nothing but its signature does
   not need a base class, and an abstract class that exists only to look
   symmetrical is a liability. None of the three seams below has one.
3. `Core12/<Area>/<Thing>.php` and `Core13/<Area>/<Thing>.php`, each carrying
   `#[AsAlias(id: <Thing>Interface::class, public: true)]` and `final`, with
   `readonly` on the properties rather than on the class.
4. A functional test asserting that the interface resolves to the implementation
   of the **running** core version, with the expected class name computed from
   `Typo3Version` rather than written out — so one test class covers both legs
   and the shared promise of the pair is asserted on each. Only a test whose
   setup cannot exist on the other version goes into `Tests/Functional/Core12/`
   or `Core13/` under `#[Group('not-core-<the other one>')]`. See
   [Core version aware functional tests](../testing/functional-tests.md#core-version-aware-functional-tests).
5. Run `phpstan` for **both** `-t` values, each after its own `composerUpdate`.
   It is the one gate configured per core version, and the one that catches an
   implementation reaching for API the other version does not have. On this
   branch the two legs are also two PHPStan majors, so a finding fixed on one is
   not automatically gone on the other.

Nothing has to be registered by hand: `Configuration/Services.php` loads the
whole matching directory, and `composer.json` already declares both PSR-4 roots.

> [!NOTE]
> This branch is the worked example of the whole mechanism: it carries **three**
> version aware seams, listed below. Branch `main` carries the 2.x line for
> TYPO3 v13 and v14 and has **none** — its `Core13/` and `Core14/` hold nothing
> but a `.gitkeep`, because everything v13 and v14 differ in has so far been
> expressible in shared code. v12 and v13 differ where v13 and v14 do not.

### What the split is actually used for

The seams this extension carries are small and each of them is one API whose
**shape**, not whose behaviour, changed between v12 and v13:

- the conflict mode argument of `ResourceStorage::addFile()`, which TYPO3 v13
  turned into a native enum (#101151) while v12 only has the class constant of
  the older `DuplicationBehavior` — and v13 answers anything that is not an
  instance of the enum with `E_USER_DEPRECATED`, which
  [fails the suites](../testing/phpunit-configuration.md#strictness-policy);
- writing a site configuration, which v12 does through
  `Core\Configuration\SiteConfiguration` and v13 through the
  `Core\Configuration\SiteWriter` extracted from it — a class that does not
  exist on v12 at all;
- handing a logger to `Core\Configuration\Loader\YamlFileLoader`, which is a
  constructor argument on v13 and a `LoggerAwareInterface` setter on v12.

Two rules fall out of those three, and both are worth copying:

- **Only the operation is split, not its caller.** The implementations differ in
  a `use` statement and an argument, sometimes in one extra call. Everything
  around them — resolving the storage, composing the configuration, reading the
  file — stays in the shared class.
- **The interface models the operation, not the argument.** A method handing a
  conflict mode back to shared code would have to declare a `mixed` return: an
  enum case on one version, a string on the other. That is the version difference
  pushed back into `Classes/` in a shape neither the type system nor PHPStan can
  check. Putting the whole call inside the implementation keeps each version's
  argument type concrete.

## Configuration is the exception

The `Core12/` and `Core13/` split works for classes, because the container picks
one of them. Configuration files — TCA, TypoScript, `ext_localconf.php`,
`ext_tables.sql` — are loaded by TYPO3 from a fixed path and cannot be split
that way. A version difference there is resolved **in the file**.

Three things make that acceptable where a conditional in a class would not be:

- The difference sits **at the end of the file**, applied to the finished array
  or wrapped in a single condition block, not scattered through it. The
  configuration stays readable as one thing.
- It carries a `@todo` naming the condition under which it goes away. A version
  switch without an exit condition becomes permanent.
- It names the **changelog issue**, so the reason can be looked up. The
  changelogs ship with `typo3/cms-core` in `Documentation/Changelog/` — verify
  against them rather than from memory.

Two further rules follow from experience rather than from the mechanism:

- **Guarding an option is not the same as dropping it.** If one version ignores
  a key and another evaluates it, removing the key changes behaviour on the
  second one, silently and without an error anywhere. Guard, do not delete.
- **Look for the spelling that is correct everywhere first.** Where one call is
  valid on every supported version, that beats any switch. The
  [fixture extension](../testing/fixture-extensions.md)'s
  [`ext_localconf.php`](../../Tests/Functional/Fixtures/Extensions/example-fixture/ext_localconf.php)
  is that case: it passes `ExtensionUtility::PLUGIN_TYPE_CONTENT_ELEMENT`
  explicitly, and the constant is `'CType'` on 12.4 and 13.4 alike. Omitting it
  would be wrong in two different ways at once — v12 silently defaults to
  `'list_type'` and registers a *different* content element, while v13 defaults
  to the same value and additionally raises `E_USER_DEPRECATED` for it (13.4:
  `ExtensionUtility::configurePlugin()`, Deprecation #105076, "Plugin content
  element and plugin sub types"). One explicit argument removes both, and the
  file needs no version switch.

### The worked example: `ext_tables.sql`

[`ext_tables.sql`](../../Tests/Functional/Fixtures/Extensions/example-fixture/ext_tables.sql)
of the fixture extension is the shape of the exception that is easiest to miss,
because the file is **not** version aware — it exists *because* of a version
difference, and nothing in it is conditional.

TYPO3 v13.0 derives a database column from every TCA `columns` entry (Feature
#101553, "Auto-create DB fields from TCA columns", extended by #104311 in 13.3
for the `ctrl` derived ones). v12.4 does not: its
`Core\Database\Schema\DefaultTcaSchema` has a single
`enrichSingleTableFields()` that derives the management columns, the
`category|datetime|slug|json|uuid` types and the MM tables — and no branch for
`input`, `text`, `link`, `file` or `inline`. v13.4 splits the same class into
`enrichSingleTableFieldsFromTcaCtrl()` and
`enrichSingleTableFieldsFromTcaColumns()`, and the second one carries a case for
every column type.

Without an explicit definition the `title` and `message` columns of
`tx_examplefixture_domain_model_greeting` are therefore simply never created on
v12, and every test touching the table fails there while passing on v13.
#101553 states that an explicit `ext_tables.sql` definition takes precedence
over the derived one, so **one file serves both versions**: required on v12,
redundant on v13, correct on both.

The consequence for day to day work: **on this branch a TCA change can be a
schema change that only fails on v12.** Adding a column means adding it to
`ext_tables.sql` too, and running the functional suite against a real DBMS
rather than SQLite alone.

## Tooling and tests

- **PHPStan** is configured per core version, one directory below
  `Build/phpstan/` each. A configuration analyses only its own core version aware
  sources — `Build/phpstan/Core12/phpstan.neon` lists `Classes`,
  `Configuration`, `Core12` and `Tests` and excludes `Tests/*/Core13/*`, and
  `Build/phpstan/Core13/phpstan.neon` is the mirror image — because a directory
  written for a different core version uses API that does not exist here and
  would report nothing but false positives. The two configurations also run
  **different PHPStan majors**, see
  [Dual core setup](../development/dual-core-setup.md#the-dependency-sets-differ-by-more-than-the-core).
  See [Quality gates](../development/quality-gates.md).
- **Tests** may mirror the same layout — `Tests/Unit/Core12/`,
  `Tests/Unit/Core13/`, `Tests/Functional/Core12/` and
  `Tests/Functional/Core13/`, one such directory per supported core version —
  and a test class placed there carries the PHPUnit group of the core version it
  must **not** run on:

  ```php
  #[Group('not-core-13')]
  final class SeedWriterTest extends UnitTestCase
  {
  }
  ```

  `Build/Scripts/runTests.sh` passes `--exclude-group not-core-<version>` for
  the selected core version, so those tests are skipped automatically on the
  other one.

  **None of the four directories exists today**, and that is the right state:
  the three seams of this branch are covered by one ungrouped test each, which
  computes the implementation it expects from `Typo3Version` and therefore
  asserts the shared promise on both legs rather than on one — see step 4 of
  [the checklist](#the-checklist-for-a-real-one) and
  [Core version aware functional tests](../testing/functional-tests.md#core-version-aware-functional-tests).
  The `Build/phpstan/Core*/phpstan.neon` exclusions for `Tests/*/Core12/*` and
  `Tests/*/Core13/*` are in place regardless, so the layout is usable the moment
  a test needs it.
- Both core versions must be verified before opening a pull request, each after
  its own `composerUpdate` — see
  [Dual core setup](../development/dual-core-setup.md) and
  [Pull requests](../workflow/pull-requests.md).

## See also

- [Dependency injection](dependency-injection.md)
- [Class design](class-design.md)
- [Dual core setup](../development/dual-core-setup.md)
- [Functional tests](../testing/functional-tests.md)
