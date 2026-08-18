# TYPO3 extension `seeder`

Seeds a TYPO3 installation — pages, content elements, records of any table,
files and site configurations — from YAML definitions that ship inside the
extensions themselves, so an instance can be rebuilt from a repository instead
of being clicked together by hand.

- **Package name**: `sbuerk/seeder`
- **Extension key**: `seeder`
- **Repository**: https://github.com/sbuerk/seeder
- **License**: GPL-2.0-or-later

> [!IMPORTANT]
> **This is pre-1.0.** The seed definition format and the public API may change
> without a deprecation phase until the first stable release. See
> [Status](#status) for what already exists.

## What it does

| Command                      | Purpose                                                                 |
|------------------------------|-------------------------------------------------------------------------|
| `seeder:list`                | List every discovered seed set: identifier, title, providing extension. |
| `seeder:import <identifier>` | Import one seed set into the installation.                              |

A **seed set** is a directory `Configuration/Seeder/<name>/` in any active
package, with `config.yml` as its entry point. Discovery walks the active
packages, so an extension carries the data it needs with it and nothing has to
be registered anywhere. Every path inside a set is resolved relative to that
directory; `EXT:` paths are accepted as well.

A set can describe:

- **pages**, nested to any depth, with their content elements,
- **records of any table**, either on a page or as inline children of a
  relation,
- **files**, copied into a storage and attached as file references,
- **site configurations**, written from a template with the seeded root page.

Everything is written through the TYPO3 `DataHandler` rather than through
direct database inserts, so slugs, TCA defaults and evaluations, `sorting`, the
reference index and the cache flush all happen the way they do for an editor.

`seeder:import` takes `--dry-run` to validate and report without writing,
`--force` to import into an installation that is not empty, `--root-page` to
choose where the set is written, `--base` to override the base URL of every
site configuration it declares, and `--no-site-config` to skip them entirely.

## Status

**The seeding implementation is being built.** What is in the repository today
is the foundation: TYPO3 v13 and v14 support from one code base, the core
version aware wiring, the container based test and quality gate harness, the
GitHub Actions workflows and the release tooling.

Not there yet — each of these is a tracked issue:

- the seed definition model and the YAML parser,
- seed set discovery and `seeder:list`,
- the data map factory, record seeding, file seeding and file references,
- site configuration templates and the suppression of the site configuration
  TYPO3 writes automatically for a new page tree root,
- `seeder:import` with its dry run and its collision report.

Until those land, the sections above describe what the extension provides, not
what you can run today. The public API and the seed definition format are not
stable and may change without a deprecation phase.

## Compatibility

| Branch | Extension | TYPO3         | PHP       |
|--------|-----------|---------------|-----------|
| `main` | 1.x       | v13.4 / v14.3 | 8.2 - 8.5 |

## Installation

Seeding demo, fixture and development data is a development concern, so the
extension usually belongs in `require-dev` of the repository or the test
instance whose data you want to rebuild:

```bash
composer require --dev sbuerk/seeder
```

Require it normally when a project ships seed sets it provisions with — the
commands then exist in the deployed installation:

```bash
composer require sbuerk/seeder
```

As long as no stable version has been released, require the development version
of the main branch explicitly:

```bash
composer require --dev sbuerk/seeder:^1.0@dev
```

This additionally requires `minimum-stability: "dev"` together with
`prefer-stable: true` in the root `composer.json` file.

## Usage at a glance

```bash
# What is available in this installation?
vendor/bin/typo3 seeder:list

# Validate a set without writing anything, then import it.
vendor/bin/typo3 seeder:import demo --dry-run
vendor/bin/typo3 seeder:import demo --base='https://example.com/'
```

`packages/my_extension/Configuration/Seeder/demo/config.yml`:

```yaml
identifier: demo
title: 'Demo page tree'
pages:
  - identifier: home
    title: 'Demo'
    slug: '/'
    is_siteroot: 1
    content:
      - identifier: home-heading
        CType: header
        header: 'A frontend to look at'
sites:
  - identifier: main
    rootPage: home
```

Every key that is not structural is written to the record as a field, so a
table needs no support in the extension to be seedable. The complete key
reference is written together with the parser — see [Status](#status) — and
lands in [`Documentation/`](Documentation).

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
