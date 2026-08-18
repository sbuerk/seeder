# Functional tests

Functional tests live below [`Tests/Functional/`](../../Tests/Functional). They
boot a real TYPO3 instance with a database, so the dependency injection
container, TCA, the persistence layer and — where needed — frontend rendering
are available.

## Running

```bash
# Functional tests on SQLite (no database container required).
Build/Scripts/runTests.sh -s functional -d sqlite

# Functional tests against other database management systems.
Build/Scripts/runTests.sh -s functional -d mariadb -i 10.6
Build/Scripts/runTests.sh -s functional -d mysql -i 8.0
Build/Scripts/runTests.sh -s functional -d postgres -i 10

# A single class or method.
Build/Scripts/runTests.sh -s functional -d sqlite -- --filter ExtensionLoadedTest
```

SQLite is the fastest option and enough for most work. Run at least one other
DBMS before opening a pull request when the change touches queries, schema or
**TCA** — TYPO3 derives the schema from the TCA, so a TCA change is a schema
change.

### Database versions

`-i` selects the image version, and `runTests.sh -h` lists which ones are
accepted per DBMS: MariaDB `10.4` … `11.8`, MySQL `8.0` … `8.4`, PostgreSQL
`10` … `18`. The default is the oldest still supported version, so a run without
`-i` tests the floor of the version range rather than the comfortable case.

PostgreSQL 18 moved its data directory and refuses to start when a mount sits at
the old location. The wrapper places the mount one level higher for `18` and
above, which is the mount point the image documents for that case — nothing to
configure, but it explains why that one version is special cased.

### Query the database through the QueryBuilder, in tests too

A test that reads a table back must not do it with hand written SQL:

```php
// Passes on SQLite and MySQL, fails on PostgreSQL.
->executeQuery('SELECT pid, CType, header FROM tt_content')
```

PostgreSQL folds an **unquoted identifier to lower case**, so this asks for a
column `ctype`, which does not exist — `SQLSTATE[42703]: Undefined column`. The
`QueryBuilder` quotes identifiers and the same query is then portable:

```php
$queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('tt_content');
$queryBuilder->getRestrictions()->removeAll();
$rows = $queryBuilder->select('pid', 'CType', 'header')->from('tt_content')
    ->orderBy('sorting')->executeQuery()->fetchAllAssociative();
```

Removing the restrictions is deliberate where the subject is *what was written*
rather than what is visible: the default restrictions hide deleted and hidden
rows, and a test asserting that a record exists would otherwise silently assert
that it is also visible.

This is the concrete reason for the rule above: a defect only PostgreSQL sees
reaches CI when the change was run against SQLite alone.

### When the database refuses the connection

A run that reports many `Connection refused` errors, from
`Doctrine\DBAL\Exception\ConnectionException` down to `mysqli_sql_exception`,
is almost never a defect in the code under test. It means the suite started
before the database server was ready.

The wrapper therefore waits for the server to **answer a query** — not for its
TCP port to open, because the MySQL image runs a temporary server while it
initialises its data directory, so an open port is not a ready server. The probe
runs the vendor's own client out of the database image itself, which is the only
client guaranteed to speak the protocol of the version under test.

Two properties of that wait are worth knowing, because each of them produces
exactly the failure above when it is wrong:

- **The budget is 60 seconds.** `mysql:8.0` needs 12 to 13 seconds under docker
  to initialise a fresh data directory, roughly twice as long as under podman,
  and the workflows select docker. A 10 second budget puts the functional MySQL
  suites on a negative margin, so they fail at random.
- **A timeout aborts the run**, cleaning up and exiting non-zero with a message
  naming the server and the budget. It must not do that by signalling `SIGINT`
  to the process group: the `SIGINT` trap is only installed when `CI` is not
  `true`, so in CI the signal is a no-op, the run carries on and PHPUnit
  connects to a database that is not listening — which is how a readiness
  failure arrives disguised as dozens of test errors.

So if a run does fail this way, read the first lines rather than the last: a
message naming the server that did not answer means the database never came up,
and the run stopped there.

Remember to run both core versions, each after the matching `composerUpdate` —
see [Dual core setup](../development/dual-core-setup.md).

## The test that proves the instance boots

[`Tests/Functional/ExtensionLoadedTest`](../../Tests/Functional/ExtensionLoadedTest.php)
asserts little and is worth a lot: it cannot reach its assertions without
booting a complete TYPO3 instance with this extension installed, which compiles
the dependency injection container, executes the extension bootstrap, loads and
migrates the TCA and derives the database schema. An unresolvable service
argument or a TCA structure the other core version has migrated away fails
there rather than in whichever feature test touches it first.

It is **never removed** —
see [the two tests that must never be dropped](Index.md#the-two-tests-that-must-never-be-dropped).

## The base test case

Functional tests extend
[`Tests/Functional/AbstractFunctionalTestCase`](../../Tests/Functional/AbstractFunctionalTestCase.php),
never the testing framework class directly. It takes care of loading the
extension itself into the test instance:

```php
use SBUERK\TYPO3\Testing\TestCase\FunctionalTestCase;

abstract class AbstractFunctionalTestCase extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'sbuerk/seeder',
    ];
}
```

The `FunctionalTestCase` it extends is the one of
`sbuerk/typo3-site-based-test-trait`, which extends the testing framework class
and adds what a site based test needs. Keeping that at the root of the chain is
a rule, not a detail — see
[Site based tests](site-based-tests.md#no-test-extends-the-framework-test-case-directly).

A test needing more extensions extends the abstract class and redeclares
`$testExtensionsToLoad`. Redeclaring **replaces** the property, so the extension
itself has to be repeated:

```php
protected array $testExtensionsToLoad = [
    'sbuerk/seeder',
    'tests/example-fixture',
];
```

Loading an extension by its **composer package name** rather than by a path
works for test-only extensions too — see
[Fixture extensions](fixture-extensions.md).

## Conventions

Same as for [unit tests](unit-tests.md): `final` classes, `#[Test]` attributes,
no `test` prefix, named data provider keys.

Additionally:

- Assert against the container through `$this->get()` when verifying wiring.
  `$this->getContainer()->has()` answers whether a service is registered at all,
  which is what the core version aware tests use to prove the *other* version's
  implementation is absent.
- Import records with `importCSVDataSet()` or a DataHandler scenario rather than
  writing SQL.
- Wrap expensive fixture setup in `withDatabaseSnapshot()` so it is built once
  and restored per test.

## Core version aware functional tests

Mirroring the source layout, they live in `Tests/Functional/Core13/` and
`Tests/Functional/Core14/` and carry the group of the core version they must
**not** run on:

```php
#[Group('not-core-14')]
final class ExampleTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function interfaceIsAliasedToCoreVersionAwareImplementation(): void
    {
        $this->assertInstanceOf(Example::class, $this->get(ExampleInterface::class));
    }
}
```

See [Dual core setup](../development/dual-core-setup.md#test-grouping).

## Strictness

Notices, warnings and deprecations fail the suite, and so do a test without an
assertion, a test writing to the output and an incomplete test. This is
deliberate — see
[PHPUnit configuration](phpunit-configuration.md#strictness-policy). Do not
silence them in the test; fix the code that triggers them.

Debug output is the one to watch for here: a `var_dump()` left in a test or in
the code under test turns a green functional test red.

## Related topics

| Topic                                               | Page                                        |
|-----------------------------------------------------|---------------------------------------------|
| Loading fixture extensions by composer package name | [Fixture extensions](fixture-extensions.md) |
| Site configuration and frontend requests            | [Site based tests](site-based-tests.md)     |
| Language and application context in tests           | [Environment state](environment-state.md)   |

## See also

- [PHPUnit configuration](phpunit-configuration.md)
- [Unit tests](unit-tests.md)
- [Core version aware code](../architecture/core-version-aware-code.md)
