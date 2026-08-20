# The scenario engine

The seed format of this extension is the YAML **scenario format** of
`typo3/testing-framework` — the one TYPO3 Core's own functional tests are
written in. This page explains why the engine that reads it lives in
`Classes/Seeding/Scenario/` as a port rather than as a dependency, what was
changed in the process, and what keeps the port honest.

The format itself is documented key by key in
[Seed definitions](../development/seed-definitions.md), together with the
`config.yml` that names the scenario files of a set; this page is about the code
that reads it.

## Why the format, and not one of our own

The format already solves what a seeder needs and what a bespoke syntax would
have had to grow into: records of any table, a page tree, translations through
`languageVariants`, workspace records through `versionVariants`, DataHandler
commands through `actions`, stable uids through suggested ids, and sequential
sibling ordering. It is exercised by 60 fixtures across TYPO3 Core v12, v13 and
v14, which is a far better proving ground than anything this repository could
assemble.

It is also **stable**. The recognised key set is byte for byte identical across
testing-framework branches 7, 8, 9 and main, and across the Core fixtures of
v12, v13 and v14. What differs between those Core branches is TCA drift inside
the fixtures — `list_type` becoming a dedicated `CType`, `url` becoming `link` —
never the format. That is why the engine needs no core version aware split.

## Why it is ported and not required

`typo3/testing-framework` cannot be a production dependency of this extension:

```
typo3/testing-framework requires:
  phpunit/phpunit: ^11.2.5 || ^12.1.2 || ^13.0.2
  typo3/cms-backend|extbase|fluid|frontend: 13.*.*@dev || 14.*.*@dev
```

`seeder:import` runs in real installations. Moving that package into `require`
would install PHPUnit on every site that seeds anything, and would pull four
sysexts in as hard runtime requirements. The classes themselves have no such
problem — `DataHandlerFactory` imports **only** `Symfony\Component\Yaml\Yaml`,
and `DataHandlerWriter` only `DataHandler`, `BackendUserAuthentication` and
`GeneralUtility`. Nothing in either of them touches PHPUnit, `Testbase` or
`FunctionalTestCase`. They are a leaf that happens to sit in a test package.

So the three classes — `DataHandlerFactory`, `DataHandlerWriter` and
`EntityConfiguration`, 779 lines together — are ported into
`SBUERK\Seeder\Seeding\Scenario`, and `typo3/testing-framework` stays in
`require-dev`.

The cost of that decision is drift: upstream can change and we would not
notice. That cost is paid by a test, see below.

## What the port changed

Everything that is not listed here is unchanged.

| Change                                                                | Why                                                                                                                                                    |
|-----------------------------------------------------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------|
| Namespace `SBUERK\Seeder\Seeding\Scenario`                            | It is our code now.                                                                                                                                    |
| `final class`, `new self()` instead of `new static()`                 | Repository rule; the classes were never subclassed upstream either.                                                                                    |
| `#[Exclude]` on all three                                             | They are a builder, a writer and a value object — data, not services. Without it, directory based registration would pick them up.                     |
| PHPStan level 8 array shapes throughout                               | The baseline is empty and stays empty. No behaviour changed; only annotations were added.                                                              |
| `?int $workspaceId` became `int $workspaceId` on two private methods  | Null was never passed by any call site, and a null array key would silently have become the empty string. Behaviour is identical for every real input. |
| The `elseif ($currentIndex > 0)` branch of `setInDataMap()` was fixed | See below. This is the one deliberate behavioural divergence.                                                                                          |
| `DataHandlerWriter::__construct()` gained an optional third parameter | See below. Additive: with its default, every existing call behaves byte for byte as upstream.                                                          |

The original TYPO3 file headers are kept. The code is GPL-2.0-or-later and so is
this repository; dropping the header to make the files look native would be
dishonest about where they came from.

## The deliberate divergences

There are three. Two are forced, the third is additive - it changes nothing for
a caller that does not use it.

### The double-indexed identifier list

Upstream `setInDataMap()` reads, when the identifier is already present in the
filtered data map:

```php
$previousIndex = $identifiers[$currentIndex - 1];
$values['pid'] = '-' . $identifiers[$previousIndex];
```

`$identifiers[$currentIndex - 1]` is already the identifier. Using it a second
time as an index into a list keyed by integers is a defect: upstream emits
`Warning: Undefined array key` and writes a `pid` of a bare `'-'`. Our port
indexes once:

```php
$values['pid'] = '-' . $identifiers[$currentIndex - 1];
```

**The branch is reachable**, which was not obvious and was initially got wrong
here. Records are normally keyed by a fresh `uniqid('NEW', true)` and so are
never already present — but a version variant reuses its *ancestor's* key
rather than allocating one. An entity that maps onto `pages` without being a
node inherits the parent's node pointer for its variants, so two
`versionVariants` of one record land in the same workspace data map on the same
page, and the second write finds its own identifier already there. `pages` is
also the one table whose `-NEW` back references resolve, so the record survives
its own page filter. That is the route in, it needs no reflection, and it is
pinned by a test.

Because it is reachable, this is a real behavioural divergence and the
conformance test has to exclude exactly this case rather than assert over it.

It was fixed rather than carried because PHPStan proves the defect, and a
growing baseline is a defect in this repository. Suppressing a proven bug to
stay byte-faithful would be the wrong trade.

### A null value instruction lookup

`EntityConfiguration::assignValueInstructions()` upstream indexes
`$this->valueInstructions[$name][$value]` directly. PHP coerces a `null` offset
to the empty string and **deprecates doing so as of PHP 8.5**, which this
extension supports and whose suite fails on a deprecation. A seed definition
declaring a null value on a column that has value instructions would emit it,
so the coercion is spelled out as `$value ?? ''` instead.

The lookup still hits exactly the key it hit before — this changes no
behaviour, only how the same key is arrived at. It is listed here because it is
a diff against upstream, not because anything observable moved.

### A withheld set of suggested uids

`DataHandlerWriter::__construct()` takes a third parameter:

```php
public function __construct(
    private readonly DataHandler $dataHandler,
    private readonly BackendUserAuthentication $backendUser,
    private readonly array $withoutSuggestedUids = [],
) {}
```

and `invokeFactory()` assigns

```php
$this->dataHandler->suggestedInsertUids = array_diff_key(
    $factory->getSuggestedIds(),
    $this->withoutSuggestedUids,
);
```

where upstream assigns `$factory->getSuggestedIds()` directly. It is **strictly
additive**: with the default empty array `array_diff_key()` returns its first
argument unchanged, so every call that does not pass the parameter behaves byte
for byte as upstream does, and the conformance test needs no exclusion for it.

It exists because `--force` has to hold back the suggested uids of a colliding
table, and `invokeFactory()` assigns `suggestedInsertUids` itself - there is no
moment between "the writer was constructed" and "the uid is read" in which a
caller could reduce the array from the outside. Reaching into
`DataHandler::$suggestedInsertUids` after the writer was handed the factory
would mean reaching into a run that has already started; passing the set in is
the same decision, made at construction time. The keys are `<table>:<uid>`,
exactly as `getSuggestedIds()` keys them.

`updateDataMap()` drops the `uid` field of a record whose suggestion was held
back, guarded by the same array and therefore inert by default:

```php
if (isset($values['uid']) && isset($this->withoutSuggestedUids[$tableName . ':' . $values['uid']])) {
    unset($values['uid']);
}
```

That half is not tidiness, it is a workaround for a TYPO3 core defect that only
PostgreSQL exposes. `process_datamap()` reads the suggested uid out of the `uid`
field and passes it to `insertDB()`, which honours it only when
`suggestedInsertUids` carries it - but `postProcessDatabaseInsert()` then does

```php
if ($suggestedUid !== 0 && $connection->getDatabasePlatform() instanceof PostgreSqlPlatform) {
    $this->postProcessPostgresqlInsert($connection, $tableName);
    return $suggestedUid;
}
```

and returns that number **whether the insert used it or not**
(`DataHandler.php:9669ff`). `substNEWwithIDs` then maps the identifier to a uid
no row has, and everything pointing at that identifier - a child's `pid`, a
sibling's `-NEW` "insert after" - points at nothing. `getSortNumber()` returns
`false`, `$fieldArray['pid']` is never set, and the run dies with a `TypeError`
in `addDefaultPermittedLanguageIfNotSet()`. MySQL and SQLite hide it, because
they take `lastInsertId()` and return the truth.

The same path is reachable upstream: `insertDB()` skips the forced uid for a
non-admin too, so any non-admin scenario run on PostgreSQL hits it. Dropping the
field costs nothing - `insertDB()` unsets it before the INSERT either way -
and `DataHandlerWriterTest` pins the chain that broke. It is worth reporting to
TYPO3 Core.

One annotation moved with it: `getSuggestedIds()` is documented as
`array<string, true>` rather than `array<string, bool>`. `true` is the only
value the class ever writes, and stating it is what lets PHPStan see the result
of the `array_diff_key()` above as the same shape rather than as a widened one.
No code path changed.

## What keeps the port honest

`Tests/Unit/Seeding/Scenario/UpstreamConformanceTest.php` runs the same
definitions through **both** our port and the upstream classes — available
because `typo3/testing-framework` is still a dev dependency — and asserts that
the data maps, command maps, table names and suggested ids match.

The comparison cannot be a plain `assertSame()`. Data map keys are
`uniqid('NEW', true)` with the dots stripped, so they differ between the two
instances and between runs, and they also appear *inside* values as `pid`, node
and parent pointers. The test therefore normalises every `NEW…` occurrence —
as a key, and anywhere inside a string value including the `-NEW…` form — to a
stable ordinal in first-appearance order, and compares the normalised
structures.

When upstream changes something, that test goes red and the change becomes a
decision instead of a surprise.

## Sibling ordering only really works for pages

`setInDataMap()` chains siblings by rewriting `pid` to `-<previous identifier>`
so DataHandler appends rather than prepends. Resolving those back references is
`resolveDataMapPageId()`, and it hard-codes the table name `'pages'`.

The consequence is easy to miss and worth stating plainly: for any **other**
table, a record that has already been chained to `-<sibling>` resolves to
`null`, drops out of the page filter, and every later record on that page is
appended after the **first** record rather than after its predecessor. Three
content elements on one page produce pids of `<page>`, `-<c1>`, `-<c1>`, and
DataHandler yields the order c1, c3, c2.

This is upstream behaviour, it is carried unchanged, and it is pinned by a test
so that nobody discovers it from a seeded site instead.

## What upstream does not do, and the pipeline must

Reading the engine tells you as much by omission as by content. None of the
following exists in it, and each is added on top rather than inside:

- **Files.** There is no `sys_file`, no `sys_file_reference`, no storage
  handling, no FAL call of any kind — verified by grep over the whole package.
  The structural reason is that a `sys_file_reference` needs a `uid_local`, and
  the `sys_file` uid is assigned by the FAL indexer while the file is placed, so
  it cannot be declared in a scenario at all. Both halves are added on top:
  `FileSeeder` before the record pass, `FileReferenceSeeder` after it, from the
  `files:` and `references:` lists of `config.yml`. See
  [The file reference pass](seeding.md#the-file-reference-pass).

  An **inline relation** is the opposite case, and it is worth naming next to
  this one: the engine needs nothing for it either, and nothing was added. A
  declared `id` is a suggested uid, so a parent can name its children by listing
  their ids in its relation field, and `DataHandler` does the rest. See
  [Inline relations need no support](../development/seed-definitions.md#inline-relations-need-no-support).
- **Site configurations.** Core's tests write sites with their own helpers.
- **A set concept** — an identifier, a `config.yml`, discovery over active
  packages, an ordered composition of several scenario files.
- **`DataHandler::$isImporting`**, which is what suppresses the
  `autogenerated-<uid>` site configuration.
- **An admin check.** `suggestedInsertUids` is honoured by core only
  `if ($this->BE_USER->isAdmin() && …)`. For any other user the declared ids are
  ignored *silently*, and every literal cross reference in the definition then
  points at the wrong record.
- **Errors that stop anything.** `invokeFactory()` returns `void` and never
  throws; failures are collected from `DataHandler::$errorLog` into
  `getErrors()`, and a caller that does not look never learns.

## Tests

Upstream ships **no test at all** for this subsystem — no unit test, no
functional test, not one fixture; its own suite covers composer package
resolution and the CSV `DataSet`. The format is only ever exercised indirectly,
by Core tests asserting rendered output.

That gap is filled here, in `Tests/Unit/Seeding/Scenario/`, from a written
inventory of every untested behaviour of the three classes. Three of the tests
pin behaviour that is arguably wrong and is left alone deliberately:

- `hasStaticId()` reads a property that is **never written**, so its
  "Cannot assign ID multiple times" guard (`1533734370`) is unreachable and
  duplicates are caught later by `addSuggestedId()` (`1568146788`) with a
  different message.
- A version variant reads its workspace from the **processed** values. A
  `columnNames` remap of `workspace` does not raise — `(int)null` is `0`, so the
  variant silently lands in workspace 0 under the ancestor's key and
  **overwrites the live record**.
- `DataHandlerWriter::updateDataMap()` resolves a `-NEW…` value through
  `substNEWwithIDs[substr($value, 1)]`, which looks the identifier up without
  its leading minus and returns the uid without it as well. The "insert after"
  marker is lost, and the record is created *inside* the record it was meant to
  follow. It only bites from the second workspace round on.

All three are pinned as they behave today, and all three say so in their
docblock, so that a later reader changes them on purpose or not at all.

## See also

- [Seed definitions](../development/seed-definitions.md) — the format itself, key by key
- [Seeding](seeding.md) — the DataHandler behaviours seeding works around
- [Unit tests](../testing/unit-tests.md)
