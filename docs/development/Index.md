# Development

Setting up a working copy, running the tooling and keeping both supported TYPO3
versions green.

| Page                                      | Contents                                                                                                               |
|-------------------------------------------|------------------------------------------------------------------------------------------------------------------------|
| [Development environment](environment.md) | `runTests.sh`, container runtimes, the full suite and option list, passing arguments to PHPUnit.                       |
| [Dual core setup](dual-core-setup.md)     | Why the installed dependency set must match `-t`, how to verify a change against both core versions, test grouping.    |
| [Quality gates](quality-gates.md)         | Every gate and its configuration, PHPStan per core version, the CI staging and why it runs the containers with docker. |
| [Seed definitions](seed-definitions.md)   | The authoritative format specification: structural keys per level, accepted shapes, defaults, validation rules.        |
| [Seed sets and the CLI](seed-sets.md)     | Discovery across active extensions, the ordering rule, `imports`, and the `seeder:list` / `seeder:import` surface.     |

## Quick start

```bash
# Install dependencies for TYPO3 v13 on PHP 8.2 (default matrix).
Build/Scripts/runTests.sh -t 13 -p 8.2 -s composerUpdate

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

Then repeat with `-t 14`, starting again at `composerUpdate` — see
[Dual core setup](dual-core-setup.md).

## See also

- [Documentation index](../Index.md)
- [Testing](../testing/Index.md)
- [Pull requests](../workflow/pull-requests.md)
