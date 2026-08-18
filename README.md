# TYPO3 extension `seeder`

TYPO3 CMS extension skeleton supporting TYPO3 v13 and v14 within one code base,
including core version aware class loading, the container based test and
quality gate harness, the GitHub Actions workflows and the release tooling.

- **Package name**: `sbuerk/seeder`
- **Extension key**: `seeder`
- **Repository**: https://github.com/sbuerk/seeder
- **License**: GPL-2.0-or-later

## Compatibility

| Branch | Extension | TYPO3     | PHP       |
|--------|-----------|-----------|-----------|
| main   | 1.x       | v13 / v14 | 8.2 - 8.5 |

## Installation

```bash
composer require sbuerk/seeder
```

As long as no stable version has been released, require the development version
of the main branch explicitly:

```bash
composer require sbuerk/seeder:^1.0@dev
```

This additionally requires `minimum-stability: "dev"` together with
`prefer-stable: true` in the root `composer.json` file.

## Documentation

| For                        | Where                                                         |
|----------------------------|---------------------------------------------------------------|
| Users and integrators      | [`Documentation/`](Documentation), rendered to docs.typo3.org |
| Developers and maintainers | [`docs/`](docs/Index.md)                                      |
| Contributors, entry point  | [`CONTRIBUTING.md`](CONTRIBUTING.md)                          |
| AI coding agents           | [`AGENTS.md`](AGENTS.md)                                      |

```bash
# Render once, as CI does. Must pass without errors.
Build/Scripts/runTests.sh -s renderDocumentation

# Serve it while writing, re-rendering on every save, on port 1337.
Build/Scripts/runTests.sh -s watchDocumentation
```

The rendered output is written to the git-ignored `Documentation-GENERATED-temp/`
directory.

## Development

All tests and quality tools run in containers through the
[`Build/Scripts/runTests.sh`](Build/Scripts/runTests.sh) wrapper. The only
requirement on the host is a container runtime — **podman** (preferred) or
**docker**.

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

Everything has to pass for **both** TYPO3 v13 and v14, each after the matching
`composerUpdate` — see
[Dual core setup](docs/development/dual-core-setup.md).

→ [`CONTRIBUTING.md`](CONTRIBUTING.md) for the contribution workflow ·
[`docs/`](docs/Index.md) for the full developer documentation

## License

This extension is published under the [GPL-2.0-or-later](LICENSE) license.
