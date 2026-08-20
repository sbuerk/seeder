# Architecture

How the code base is organised and which design rules apply to it. On the three
rule pages the code examples are sketches — they show the shape a rule asks for,
and the class names in them are not classes of this repository. The two seeding
pages are the other way round: everything they name exists, and every claim about
TYPO3 behaviour on them was read out of the core on disk.

| Page                                                  | Contents                                                                                                                                                |
|-------------------------------------------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------|
| [Core version aware code](core-version-aware-code.md) | `Classes/` vs `Core13/` vs `Core14/`, container based selection of the right variant, the interface + abstract + implementation pattern.                |
| [Dependency injection](dependency-injection.md)       | Symfony DI attributes instead of `Services.yaml`, stateless services, private by default, `#[AsAlias]`, non-shared services.                            |
| [Class design](class-design.md)                       | `final readonly` and what it implies for hierarchies, method injection in abstract classes, data objects vs services, the two accepted PHPStan ignores. |
| [The scenario engine](scenario-engine.md)             | Why the seed format is the testing-framework scenario format, why the engine is a port and not a dependency, and the test that keeps it honest.         |
| [Seeding](seeding.md)                                 | Why seeding goes through DataHandler, the admin user, declaration order, suggested uids, placeholders, the two-pass file write, `isImporting`.          |
| [Site configurations](site-configuration.md)          | Writing a site from a template, why `rootPageId` always wins, the refusal of an existing identifier, the uncovered-site-roots report.                   |

## The short version

- One code base serves TYPO3 v13 and v14. Version differences are resolved by
  **splitting classes**, not by conditionals in shared code.
- Services are **stateless** and wired with **attributes on the class**, never
  with service definitions in `Services.php` or a `Services.yaml`.
- Services are **private** unless something really has to fetch them from the
  container.
- Classes are **`final readonly`** unless a framework constraint prevents it.
- Data is not a service: models, entities, value objects and DTOs are created,
  not injected — and they carry **`#[Exclude]`** so directory based service
  registration does not pick them up.

## See also

- [Documentation index](../Index.md)
- [Testing](../testing/Index.md)
- [Quality gates](../development/quality-gates.md)
