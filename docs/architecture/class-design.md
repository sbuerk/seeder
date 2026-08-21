# Class design

## The PHP 8.1 rule: `readonly` sits on the properties

> **Classes are `final`, never `final readonly`. Immutability is declared per
> property.**

This branch supports **TYPO3 v12.4**, and `typo3/cms-core` 12.4 supports **PHP
8.1**. `readonly` on a *class* is a PHP 8.2 feature: on 8.1 the declaration does
not merely behave differently, it does not parse.

```
PHP Parse error:  syntax error, unexpected token "readonly",
expecting "abstract" or "final" or "class"
```

So `final readonly class` becomes `final class` with `readonly` written on every
property, and `abstract readonly class` becomes `abstract class` the same way —
on declared properties and on promoted constructor parameters alike. Nothing
else about the class changes.

```php
#[Exclude]
final class SeedFile
{
    public function __construct(
        public readonly string $identifier,
        public readonly string $source,
        public readonly string $folder = '/',
    ) {}
}
```

For everything this repository relies on, the two spellings are equivalent: PHP
enforces write-once on a `readonly` property whether the keyword came from the
property or from the class, on 8.1 exactly as on 8.4. What class level `readonly`
adds on top is two things — it covers *every* property automatically, and it
forbids dynamic properties outright. Property level `readonly` does neither, so
both have to be held by hand:

1. **No `readonly class` remains.**
   `grep -rn 'readonly class' Classes Core12 Core13 Tests` must return prose and
   nothing else. `Build/Scripts/runTests.sh -t 12 -p 8.1 -s lintPhp` is the gate
   that catches a miss — and it is the *only* one that does, because the
   declaration parses on every other supported PHP version.
2. **Every property is `readonly`.** This is the part that goes wrong silently.
   Dropping the class keyword makes every property of that class mutable and
   *nothing* reports it — not the linter, not PHPStan, not a test. A promoted
   constructor parameter written `private Foo $foo` was immutable while the
   keyword was on the class and is not any more; it has to read
   `private readonly Foo $foo`. Check the constructor **and** the declared
   properties, because a class using `#[Required]` method injection carries its
   state outside the constructor.
3. **A property that cannot be `readonly` is a finding, not a workaround.** If a
   class writes a property after construction it is not a service — see
   [Dependency injection](dependency-injection.md).

A dynamic property, the second thing the class keyword forbade, is silently
allowed on PHP 8.1 and deprecated from 8.2 on. On the versions where the language
does not catch it the suites do, because they
[fail on deprecations](../testing/phpunit-configuration.md#strictness-policy).

> [!NOTE]
> **`@todo` — the rule reverts when TYPO3 v12 support is dropped.** With v13.4 as
> the lowest supported core version the PHP floor rises to 8.2, `readonly class`
> parses, and the hierarchy goes back to `abstract readonly class` /
> `final readonly class` with the property keywords becoming redundant. Until
> then, a class arriving from a v13+v14 branch as `final readonly class` is
> rewritten on the way in.

Because the keyword is on the properties, the hierarchy rules that class level
`readonly` imposes — a `readonly` class can only extend a `readonly` class and
vice versa — do not apply here at all. An abstract base class and the classes
extending it are plain `abstract class` and `final class`:

| Abstract base class | Extending classes | Properties         |
|---------------------|-------------------|--------------------|
| `abstract class`    | `final class`     | each is `readonly` |

`final` itself is unaffected by any of this and stays the default: services are
replaced through the container, not through inheritance.

### Constants in traits are PHP 8.2 as well

The same PHP 8.1 floor rules out a second construct: a `trait` cannot declare a
`const` before PHP 8.2 —

```
PHP Fatal error:  Traits cannot have constants
```

[`Tests/ExtensionCoreVersionCompatTestsTrait`](../../Tests/ExtensionCoreVersionCompatTestsTrait.php)
returns the core version numbers it used to name as constants from
`private function`s instead. Class constants and interface constants are
unaffected; only traits are — so this reverts together with the `readonly` rule
above, when PHP 8.1 support is dropped.

## Abstract classes must not use constructor injection

The constructor of an abstract class is part of the API of every class
extending it. Adding a dependency to it changes the signature of all extending
classes — including those in other extensions — and therefore breaks them.

Abstract classes therefore use **method injection**: an `inject*()` method
carrying Symfony's `#[Required]` attribute. The constructor stays free for the
extending classes:

```php
use Symfony\Contracts\Service\Attribute\Required;

abstract class AbstractSeedWriter implements SeedWriterInterface
{
    /** @phpstan-ignore property.uninitializedReadonly */
    protected readonly Typo3Version $typo3Version;

    #[Required]
    public function injectTypo3Version(Typo3Version $typo3Version): void
    {
        /** @phpstan-ignore property.readOnlyAssignNotInConstructor */
        $this->typo3Version = $typo3Version;
    }
}
```

Note that the property carries `readonly` itself — under the rule above there is
no class keyword to inherit it from, and an injected property is exactly the kind
that is easy to leave mutable by accident.

Concrete (`final`) classes have no such problem and use plain **constructor
injection**, ideally with promoted properties.

## Data objects are not services

Models, entities, value objects and DTOs represent data, not behaviour. They are
created with `new`, by a factory or by the persistence layer, and never fetched
from the container.

### `#[Exclude]` is mandatory on all of them

`Configuration/Services.php` registers whole directories with `$services->load()`,
which does not distinguish a service from a data object. Every data object below
a loaded directory therefore needs Symfony's `#[Exclude]` attribute — **without
exception, Extbase models included**:

```php
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final class ExampleResult
{
    public function __construct(
        public readonly string $identifier,
        public readonly int $count,
    ) {}
}
```

The failure mode is worth knowing, because it explains why this is a rule rather
than a preference. A data object that is registered but never referenced is
removed again when the container is compiled, so **nothing breaks and nothing
warns** — the omission is invisible until someone type hints the data object
somewhere. Then the container fails to build, with an error pointing at the data
object rather than at the code that referenced it:

```
Cannot autowire service "Vendor\Extension\Dto\ExampleResult": argument
"$identifier" of method "__construct()" is type-hinted "string", you should
configure its value explicitly.
```

Adding the attribute when the class is written costs nothing; finding this later
costs an afternoon.

### Immutability, and where Extbase differs

Keep data objects immutable where the framework allows it — `final` with
`readonly` promoted constructor properties, named arguments at the call site —
and give them explicit types rather than untyped arrays.

Extbase domain models are the exception, and **only to this part of the rule**:
Extbase requires mutable properties and a no-argument constructor, because the
data mapper assigns properties by reflection on an instance it creates without
calling the constructor. So an Extbase model is neither `readonly` nor
constructor-injected — but it is still a data object, and it still carries
`#[Exclude]`. The
[`Greeting`](../../Tests/Functional/Fixtures/Extensions/example-fixture/Classes/Domain/Model/Greeting.php)
model of the [fixture extension](../testing/fixture-extensions.md) shows both.

See [Dependency injection](dependency-injection.md#rules).

## The two PHPStan ignores on injected readonly properties

PHP allows a readonly property to be initialized by **any** method of its
declaring class, not only the constructor — an `inject*()` method therefore
initializes it perfectly legally, and PHP still rejects every later write.
PHPStan is stricter and insists on the constructor, which produces exactly two
findings on such a property:

| Identifier                                | Reported on              |
|-------------------------------------------|--------------------------|
| `property.uninitializedReadonly`          | the property declaration |
| `property.readOnlyAssignNotInConstructor` | the assignment           |

Both are ignored by their identifier, as shown in the example above — narrowly,
on the property declaration and on the assignment, never on the file or the
class. **This is required and absolutely fine here**: it is the only way to
combine the two rules this repository holds — a constructor kept free for
extending classes, and immutable service state — and PHP itself still guarantees
the immutability that `readonly` promises.

Do **not** take this as a licence to silence PHPStan elsewhere. The ignores are
acceptable **only** for a property that is

1. declared in an abstract class as `protected readonly`,
2. assigned exactly once, in its own `#[Required]`-annotated `inject*()` method,
3. never written anywhere else.

Anything beyond that is a misuse: ignore the finding nowhere else, never widen
an ignore to a whole file or class, and never use it to work around a genuine
mutability problem. Prefer fixing the finding — and if a service really needs
to change state after construction, it is not a service (see
[Dependency injection](dependency-injection.md)).

One thing to check when writing such an ignore on this branch: the two legs run
**different PHPStan majors** (1.12 on v12, 2.x on v13 — see
[Dual core setup](../development/dual-core-setup.md#the-dependency-sets-differ-by-more-than-the-core)).
Both identifiers above exist in 1.12.34, so the same spelling works on the older
leg; an ignore is nevertheless only proven once `phpstan` has run green for both
`-t 12` and `-t 13`.

## See also

- [Dependency injection](dependency-injection.md)
- [Core version aware code](core-version-aware-code.md)
- [Dual core setup](../development/dual-core-setup.md)
- [Quality gates](../development/quality-gates.md)
