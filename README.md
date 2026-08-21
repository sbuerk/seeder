# TYPO3 extension `data_factory`

Seeds a TYPO3 installation - pages, content elements, records of any table,
the relations between them, files, the references attaching those files to
records, and site configurations - from YAML definitions that ship inside the
extensions themselves, so an instance can be rebuilt from a repository instead
of being clicked together by hand. The records are written in the **scenario
format** of `typo3/testing-framework`, the one TYPO3 Core's own functional
tests are written in.

- **Package name**: `sbuerk/data-factory`
- **Extension key**: `data_factory`
- **Repository**: https://github.com/sbuerk/data-factory
- **License**: GPL-2.0-or-later

[![Packagist Version](https://img.shields.io/packagist/v/sbuerk/data-factory)](https://packagist.org/packages/sbuerk/data-factory)

> [!IMPORTANT]
> **This is the 2.x line: TYPO3 v13.4 and v14.3.** TYPO3 v12.4 is served by the
> 1.x line on branch [`1`](https://github.com/sbuerk/data-factory/tree/1) - see
> [Compatibility](#compatibility) for both lines.

## What it does

| Command                            | Purpose                                                                 |
|------------------------------------|-------------------------------------------------------------------------|
| `data-factory:list`                | List every discovered seed set: identifier, title, providing extension. |
| `data-factory:import <identifier>` | Import one seed set into the installation.                              |

A **seed set** is a directory `Configuration/DataFactory/<name>/` in any active
package, with `config.yml` as its entry point. Discovery walks the active
packages, so an extension carries the data it needs with it and nothing has to
be registered anywhere. Every path inside a set is resolved relative to that
directory; `EXT:` paths are accepted as well.

`config.yml` describes the *set*: its `identifier`, `title` and `description`,
the scenario files it is built from, the files it provisions, the records those
files are attached to and the site configurations it writes. The records
themselves live in the scenario files it names.

A set can describe:

- **pages**, nested to any depth, with **records of any table** on them,
- **translations** of a record, through `languageVariants`,
- **workspace records**, through `versionVariants`,
- **`DataHandler` commands** - move, delete and discard - through `actions`,
- **files**, copied into a storage,
- **file references**, attaching a seeded file to a field of a seeded record,
- **site configurations**, written from a template with a seeded page as root.

A **relation between records** needs no construct of its own: every record has
a uid before it is written, so a parent names its children by writing their
declared ids into its relation field, and `DataHandler` resolves that list -
including the `parentid`, `parenttable` and `sorting_foreign` of an inline
relation. A file is the exception, and the reason `config.yml` has a
`references` key: a `sys_file_reference` points at its file by a uid the FAL
indexer only hands out while the file is being placed.

Everything is written through the TYPO3 `DataHandler` rather than through
direct database inserts, so slugs, TCA defaults and evaluations, `sorting`, the
reference index and the cache flush all happen the way they do for an editor.

`data-factory:import` takes five options:

| Option             | Does                                                                        |
|--------------------|-----------------------------------------------------------------------------|
| `--dry-run`        | Parse, validate and report what an import would do. Writes nothing.         |
| `--force`          | Import although the set suggests uids the installation already uses.        |
| `--root-page`      | The page the set is written below. `0`, the page tree root, is the default. |
| `--base`           | Override the `base` of every site configuration the set declares.           |
| `--no-site-config` | Skip the site configurations entirely.                                      |

`--force` gives up the declared uids of a colliding table, so the records land
under free ones and nothing in the way is deleted or overwritten - which is why
a set declaring sites cannot be forced past a collision in `pages`: a site names
its root page by that uid. The command exits with a distinct code per failure,
so a deployment script can tell "no such set" from "that would overwrite
something".

An import runs on the command line as an administrator - the TYPO3 console
authenticates the `_cli_` backend user, which is one by default - because TYPO3
honours a declared uid for an administrator only and ignores it silently
otherwise. A set that brings files also needs a file storage to write into;
`typo3 setup` creates `fileadmin/` on a new installation.

Every record is written with a known uid: the `id` its entity declares, or one
handed out from 10000 upwards. Declaring it is what makes a seeded page tree
the same tree in every installation, and what a relation and a site's
`rootPage` point at. Because a suggested uid is a suggestion and not a demand,
an import refuses to run when one of them is already taken and names the
records in the way - a deleted record occupies its uid as much as any other
one.

Importing a set also suppresses the `autogenerated-<uid>` site configuration
TYPO3 writes for a new site root, and reports a seeded site root that ends up
covered by no site configuration.

## Status

**Released and maintained.** Both commands, the set descriptor, the scenario
format, record seeding, file seeding, file references and site configurations
are implemented, covered by unit and functional tests, and green on TYPO3 v13.4
and v14.3.

The **supported interface** is the scenario format, `config.yml` and the two
commands with their options and exit codes. A change to it that is not
backwards compatible goes into a new major version and carries a
`Breaking-*.rst` entry in
[`Documentation/Changelog/`](Documentation/Changelog). Everything below
`Classes/` is `@internal` - it is the implementation of that interface, it
carries no compatibility promise, and it may change in any release.

Deliberate limitations, worth knowing before adopting it:

- Seeding **writes**. It does not reconcile an existing tree against a
  definition, and no import is idempotent.
- Nothing is deleted or overwritten. A uid collision and an existing site
  identifier are refusals, not merges.
- A **file reference** reaches only the records of its own set. The `uid` it
  names is resolved against what the run writes, so a file cannot be attached
  to a record that is already in the installation.
- A file reference is declared in `config.yml`, never in a scenario file - the
  scenario format has no concept of a file and does not gain one here.
- A seeded `be_users` record has to declare `username` and `password` itself.

## Compatibility

| Branch | State      | Extension | TYPO3         | PHP       |
|--------|------------|-----------|---------------|-----------|
| `main` | active     | 2.x       | v13.4 / v14.3 | 8.2 - 8.5 |
| `1`    | maintained | 1.x       | v12.4         | 8.1 - 8.4 |
| `1`    | maintained | 1.x       | v13.4         | 8.2 - 8.4 |

Branch `main` - this branch - is the 2.x line for TYPO3 v13.4 and v14.3. One row
is enough for it, because the PHP range is the same for both of its core
versions.

Branch `1` is the 1.x line, on TYPO3 v12.4 and v13.4. It gets one row per TYPO3
version because the PHP ranges differ there: PHP 8.1 is supported for **TYPO3
v12 only**, as `typo3/cms-core` 13.4 requires PHP `^8.2` and a v13 dependency
set on PHP 8.1 cannot be installed at all. The lowest supported v12 patch level
is **12.4.22**, the floor `fgtclb/environment-state-manager` 1.0 raises it to.

Both lines are released. `main` is the active line and gets new features; branch
`1` is maintained for installations still on TYPO3 v12.4 and gets fixes. The two
lines are released independently and neither is merged into the other, so a fix
that applies to both is applied to both. TYPO3 v13.4 is served by either, which
means an installation on v13.4 can move between the lines without changing a
seed set.

## Installation

Seeding demo, fixture and development data is a development concern, so the
extension usually belongs in `require-dev` of the repository or the test
instance whose data you want to rebuild:

```bash
composer require --dev sbuerk/data-factory
```

Require it normally when a project ships seed sets it provisions with — the
commands then exist in the deployed installation:

```bash
composer require sbuerk/data-factory
```

Either command resolves to the line matching the installed core - 2.x on TYPO3
v13.4 and v14.3, 1.x on v12.4 - because each line constrains `typo3/cms-core`
itself. Pin the line where a project wants it written down:
`sbuerk/data-factory:^2.0` here, `sbuerk/data-factory:^1.0` for the 1.x line.

## Usage at a glance

```bash
# What is available in this installation?
vendor/bin/typo3 data-factory:list

# Validate a set without writing anything, then import it.
vendor/bin/typo3 data-factory:import demo --dry-run
vendor/bin/typo3 data-factory:import demo --base='https://example.com/'
```

Without an identifier the command asks which set to import; without a terminal
to ask on it lists them and exits `2`. `data-factory:import --help` documents
every exit code.

A minimal set is two files. `config.yml` describes the set and names the
scenario files it is built from:

`packages/my_extension/Configuration/DataFactory/demo/config.yml`:

```yaml
identifier: demo
title: 'Demo page tree'
scenarios:
  - Scenario.yaml
```

`packages/my_extension/Configuration/DataFactory/demo/Scenario.yaml`:

```yaml
entitySettings:
  page:
    isNode: true
    tableName: 'pages'
    parentColumnName: 'pid'
    nodeColumnName: 'pid'
    columnNames: {id: 'uid'}
    defaultValues: {pid: 0, hidden: 0, doktype: 1}
  content:
    tableName: 'tt_content'
    nodeColumnName: 'pid'
    columnNames: {id: 'uid', title: 'header', type: 'CType'}
    defaultValues: {pid: 0, hidden: 0}

entities:
  page:
    - self: {id: 1000, title: 'Demo', slug: '/', is_siteroot: 1}
      entities:
        content:
          - self: {id: 1001, title: 'A frontend to look at', type: 'header'}
```

`entitySettings` names the table an entity is written to and how its properties
map onto columns; every key that is not structural is written to the record as a
field, so a table needs no support in the extension to be seedable. The complete
key reference is in
[`Documentation/Configuration/`](Documentation/Configuration) for integrators
and in
[`docs/development/seed-definitions.md`](docs/development/seed-definitions.md)
for developers, where it also says why each rule exists.

A set that also writes a **site configuration** is three files. `sites:` in
`config.yml` names the site, and the site itself is a template directory next to
it - `Sites/<identifier>/config.yaml`, the same shape as a site below
`config/sites/`, which a `template` key may point elsewhere. `rootPage` is the
uid of a seeded page, and the import writes the uid that page actually got into
the template's `rootPageId`, whatever the template says:

`packages/my_extension/Configuration/DataFactory/demo/config.yml`, extended:

```yaml
identifier: demo
title: 'Demo page tree'
scenarios:
  - Scenario.yaml
sites:
  - identifier: main
    rootPage: 1000
```

`packages/my_extension/Configuration/DataFactory/demo/Sites/main/config.yaml`:

```yaml
rootPageId: 1000
base: 'https://example.com/'
languages:
  - languageId: 0
    title: 'English'
    locale: 'en_US.UTF-8'
    base: '/'
```

## Documentation

| For                        | Where                                                                                                    |
|----------------------------|----------------------------------------------------------------------------------------------------------|
| Users and integrators      | [`Documentation/`](Documentation) · [rendered](https://docs.typo3.org/p/sbuerk/data-factory/main/en-us/) |
| What changed per version   | [`Documentation/Changelog/`](Documentation/Changelog)                                                    |
| Developers and maintainers | [`docs/`](docs/Index.md)                                                                                 |
| Contributors, entry point  | [`CONTRIBUTING.md`](CONTRIBUTING.md)                                                                     |
| AI coding agents           | [`AGENTS.md`](AGENTS.md)                                                                                 |

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

## Support

Questions, bug reports and feature requests belong in the
[issue tracker](https://github.com/sbuerk/data-factory/issues). There is no
support channel besides it. Include the seed set, the command line and the
output of `data-factory:import … --dry-run` - it reports what an import would do
without writing anything.

## License

This extension is published under the [GPL-2.0-or-later](LICENSE) license.
