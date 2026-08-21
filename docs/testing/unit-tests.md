# Unit tests

Unit tests live below [`Tests/Unit/`](../../Tests/Unit) and extend
`TYPO3\TestingFramework\Core\Unit\UnitTestCase`. They run without a database and
without a TYPO3 instance.

## Running

```bash
# Unit tests.
Build/Scripts/runTests.sh -s unit

# Unit tests in random order (add "-o <seed>" to replay a specific order).
Build/Scripts/runTests.sh -s unitRandom

# A single class or method.
Build/Scripts/runTests.sh -s unit -- --filter VersionCompatTest
```

`unitRandom` exists to catch tests that depend on execution order. When it fails,
the output contains the seed; replay it with `-o <seed>` to reproduce.

Remember to run both core versions, each after the matching `composerUpdate` —
see [Dual core setup](../development/dual-core-setup.md).

## Conventions

- Test classes are `final` and named `<SubjectUnderTest>Test`.
- Test methods carry the PHPUnit `#[Test]` attribute and must **not** be
  prefixed with `test` — enforced by the `checkTestMethodsPrefix` gate:

  ```php
  #[Test]
  public function getExtensionKeyReturnsExtensionKey(): void
  {
      // ...
  }
  ```

- Method names describe the expected behaviour, not the mechanics:
  `getExtensionKeyReturnsExtensionKey()`, not `testGetExtensionKey()`.
- Every test asserts something. A test without an assertion is risky and
  therefore a failure. When the behaviour under test is "this does not throw",
  say so with `self::expectNotToPerformAssertions()` instead of leaving the
  method bare — see
  [PHPUnit configuration](phpunit-configuration.md#strictness-policy).
- Nothing is written to the output. A leftover `var_dump()` or `echo` makes the
  test risky and fails the run.
- Data providers are `public static` and return a `\Generator` with named keys,
  so a failing case is identifiable in the output:

  ```php
  public static function expectedLoadedExtensionIdentifiers(): \Generator
  {
      yield 'composer package name: sbuerk/data-factory' => ['identifier' => 'sbuerk/data-factory'];
      yield 'extension key: data_factory' => ['identifier' => 'data_factory'];
  }
  ```

## Core version aware unit tests

Tests for classes below `Core13/` and `Core14/` mirror that layout in
`Tests/Unit/Core13/` and `Tests/Unit/Core14/`, and carry the group of the core
version they must **not** run on:

```php
#[Group('not-core-14')]
final class SeedWriterTest extends UnitTestCase
{
}
```

Note the inverted logic: the class above runs on v13 only, and a test without
any group runs everywhere. See
[Dual core setup](../development/dual-core-setup.md#test-grouping).

The same grouping is what makes
[`Tests/Unit/VersionCompatTest`](../../Tests/Unit/VersionCompatTest.php) work:
it asserts that a run with `-t 13` really is v13 and one with `-t 14` really is
v14, so a stale `.Build/` cannot produce a green suite that proved nothing. That
test is **never removed** —
see [the two tests that must never be dropped](Index.md#the-two-tests-that-must-never-be-dropped).

## Testing classes with injected dependencies

A class using `#[Required]` method injection is constructed and injected by hand
in a unit test — there is no container, so nothing calls the `inject*()` methods
for it:

```php
$subject = new SeedWriter();
$subject->injectTypo3Version(new Typo3Version());

$this->assertSame(3, $subject->write('pages'));
```

Forgetting the injection call does not produce a helpful failure: the readonly
property is simply uninitialized and the first read throws. Inject everything
the code path under test touches.

If the wiring itself is what needs verification — that the container hands out
the implementation of the running core version — that is not a unit test. It
belongs in a [functional test](functional-tests.md), where the real container
is available.

## See also

- [PHPUnit configuration](phpunit-configuration.md)
- [Functional tests](functional-tests.md)
- [Class design](../architecture/class-design.md)
