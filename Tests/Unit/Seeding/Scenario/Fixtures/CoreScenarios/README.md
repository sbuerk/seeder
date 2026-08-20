# TYPO3 Core scenario fixtures

Verbatim copies of scenario definitions from the TYPO3 Core `main` branch,
below `typo3/sysext/*/Tests/Functional/`. They are GPL-2.0-or-later, as is this
repository.

They are here because `UpstreamConformanceTest` needs the widest available
exercise of the scenario format, and Core's own fixtures are it: 20 files that
between them use every key the parser reads, against real TCA, maintained by
people who use the format daily. Writing an equivalent set by hand would prove
only that our reading of the format agrees with itself.

They are **copied rather than referenced** so the test does not depend on a
core checkout, on `.Build/`, or on anything that is not in the repository.

Do not edit them. If one needs updating, replace it with the current upstream
file so it keeps saying what upstream says.

| File                                | Origin (path under `typo3/sysext/`)                                              |
|-------------------------------------|----------------------------------------------------------------------------------|
| `AspectScenario.yaml`               | `core/Tests/Functional/Routing/Aspect/Fixtures/`                                 |
| `CommonScenario.yaml`               | `backend/Tests/Functional/Fixtures/`                                             |
| `ContentScenario.yaml`              | `frontend/Tests/Functional/DataProcessing/Fixtures/`                             |
| `DefaultViewScenario.yaml`          | `backend/Tests/Functional/View/Fixtures/`                                        |
| `HrefLangScenario.yml`              | `seo/Tests/Functional/Fixtures/`                                                 |
| `LanguageComparisonScenario.yaml`   | `backend/Tests/Functional/View/Fixtures/`                                        |
| `LocalizedPageRenderingScenarioD.yaml` | `frontend/Tests/Functional/SiteHandling/LocalizedPageRendering/Fixtures/`      |
| `MetadataScenario.yaml`             | `frontend/Tests/Functional/Aspect/Fixtures/`                                     |
| `MountPointScenario.yaml`           | `frontend/Tests/Functional/SiteHandling/Fixtures/`                               |
| `PagesWithBEPermissionsScenario.yaml` | `backend/Tests/Functional/Controller/Page/Fixtures/`                            |
| `SlugScenario.yaml`                 | `frontend/Tests/Functional/SiteHandling/Fixtures/`                               |
