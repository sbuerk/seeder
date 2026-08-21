# Development

Setting up a working copy, running the tooling and keeping both supported TYPO3
versions green.

| Page                                      | Contents                                                                                                                                |
|-------------------------------------------|-----------------------------------------------------------------------------------------------------------------------------------------|
| [Development environment](environment.md) | `runTests.sh`, container runtimes, the full suite and option list, passing arguments to PHPUnit.                                        |
| [Dual core setup](dual-core-setup.md)     | Why the installed dependency set must match `-t`, how to verify a change against both core versions, test grouping.                     |
| [Quality gates](quality-gates.md)         | Every gate and its configuration, PHPStan per core version, the CI staging and why it runs the containers with docker.                  |
| [Seed definitions](seed-definitions.md)   | The authoritative format specification: the `config.yml` descriptor, file references, inline relations, the scenario format key by key. |
| [Seed sets and the CLI](seed-sets.md)     | Discovery across active extensions, the ordering rule, `imports`, and the `data-factory:list` / `data-factory:import` surface.          |

## Quick start

```bash
# Install dependencies for TYPO3 v12 on PHP 8.2 (the defaults).
Build/Scripts/runTests.sh -t 12 -p 8.2 -s composerUpdate

# Quality gates.
Build/Scripts/runTests.sh -s cgl -n
Build/Scripts/runTests.sh -s phpstan
Build/Scripts/runTests.sh -s lintPhp

# Tests.
Build/Scripts/runTests.sh -s unit
Build/Scripts/runTests.sh -s functional -d sqlite

# All available options.
Build/Scripts/runTests.sh -h
```

Then repeat with `-t 13`, starting again at `composerUpdate` — and once more
with `-t 12 -p 8.1` when a change adds or moves a class, because a dependency
set carries the PHP version it was installed for. See
[Dual core setup](dual-core-setup.md).

## See also

- [Documentation index](../Index.md)
- [Testing](../testing/Index.md)
- [Pull requests](../workflow/pull-requests.md)
