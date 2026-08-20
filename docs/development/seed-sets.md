# Seed sets and the CLI

How a set is found, in which order, and what the two commands do with it. The
descriptor and the scenario format a set is written in are specified in
[Seed definitions](seed-definitions.md); what happens while it is written is on
[Seeding](../architecture/seeding.md).

## Discovery

A seed set is a directory `Configuration/Seeder/<name>/` with a `config.yml` in
it. That `config.yml` names the **scenario files** the records come from, and
those files sit next to it or anywhere an `EXT:` path reaches:

```
Configuration/Seeder/demo/
├── config.yml               identifier, title, "scenarios", "files", "sites"
├── Scenario.yaml            the records, in the scenario format
├── Files/
└── Sites/
```

`SeedSetRepository` walks the **active packages** of the installation and
collects those directories:

- a set is available exactly when the extension shipping it is installed *and*
  activated;
- there is no configured path list, and nothing outside a package directory is
  ever scanned;
- an extension without a `Configuration/Seeder/` directory is skipped, which is
  the normal case for all but a handful of packages;
- a directory below `Configuration/Seeder/` **without** a `config.yml` is not a
  set and is passed over without a word — that is what lets a set keep partials,
  files and site templates next to itself.

The location is not configurable. A set is found where the format says it is, or
the format has no meaning.

### Discovery reads metadata

A `config.yml` is read here with `Yaml::parseFile()`, and only `identifier`,
`title` and `description` are looked at. It is deliberately **not** handed to
`SeedDefinitionParser`, which would follow the `imports` of the set, read every
scenario file it names and validate every entity and every file of it.

Parsing in full to show a title would make `seeder:list` as fragile as the least
well maintained set in the installation: one set with a typo in a page record and
*no* set can be listed any more — including the ones that are fine, and including
the listing an integrator would use to find out which sets exist at all. Listing
therefore costs one YAML parse per set, and validating a set is what
`seeder:import` does, to the set it was asked for.

The consequence for the format is that those three keys have to be declared in
`config.yml` itself and cannot be pulled in through an `imports`.

There is one failure discovery cannot work around: a `config.yml` that is not
readable YAML, is not a map, or declares no identifier and title. Such a set has
no name to be listed under and none to be left out by, so it stops discovery
entirely — for both commands, and including a set nobody asked for. A discovery
that silently leaves sets out cannot answer the question whether an identifier
exists.

### The order is part of the contract

Sets are returned in **discovery order**:

1. the active packages, in the order `PackageManager::getActivePackages()`
   returns them — which is the order the installation itself loads them in;
2. within one package, the set directories sorted by path with `SORT_STRING`, so
   the result does not depend on the order the file system hands out directory
   entries, and no locale can reorder a listing.

Sorting by identifier instead was rejected: identifiers are not unique until they
have been checked to be, and sorting by a key that may occur twice needs a tie
breaker that would have to be the package order anyway. Discovery order also puts
a set next to the package it came from, which is what a duplicate report is
about.

`SeedSetRepository` is stateless and re-reads on every call. Should that ever be
measurably too slow, it is cached through the TYPO3 caching framework — never
into a property, which would make the result depend on how long the request that
asked for it had been running.

### Duplicated identifiers

An identifier is declared, never derived from the directory name, and it is
globally unique across all active packages. A derived identifier would turn a
collision between two packages into a silent one.

`findAll()` **keeps** duplicates: that is what was found, and dropping one of two
colliding sets there would hide the collision from the report that exists to show
it. `findByIdentifier()` refuses to resolve one — a collision is never settled by
letting a provider win — and `findDuplicates()` returns the collisions alone.

Both the extension key and the `config.yml` path are named in the report, because
two extensions colliding is the obvious case and one extension colliding with
*itself* over two set directories is the more likely mistake — where the
extension key alone would be printed twice and explain nothing.

## Imports

An entry file may pull in further YAML files with `imports`, handled by
`YamlFileLoader` — the loader the core reads its own site configurations with.
Lists are merged rather than replaced, resources resolve relative to the file
declaring them, `EXT:` paths are accepted, placeholder substitution is switched
off, and a failing import raises instead of being logged. The reasoning is in
[Seed definitions](seed-definitions.md#imports).

`imports` merges into the **descriptor**, and only into the descriptor. It is
how a set splits its own `files:` or `sites:` off into a second file; it does
not bring records in. Records live in the files `scenarios:` names, and those
are read with a plain `Yaml::parseFile()` outside the loader - which is why a
scenario file carrying `imports:` is refused as an unknown key rather than
followed.

The path handed to the loader is the **absolute path discovery found**, unchanged.
Deriving an `EXT:` path from the extension key was considered and rejected: it
would rescue the entry file and nothing else, because
`YamlFileLoader::getStreamlinedFileName()` resolves a relative import against the
importing file and checks the result with `isAllowedAbsPath()` a second time. A
set outside the project path would still fail the moment it grew an `imports:` —
and it would fail later, in a different place, for a reason the workaround had
hidden.

Instead, `seeder:import` **detects that case explicitly**: when the file exists
and `GeneralUtility::getFileAbsFileName()` still answers with an empty string, it
says so and names the project path, the public path and the file. In a Composer
installation this cannot normally happen —
`typo3/cms-composer-installers` pins the application directory to the Composer
root (*"Changing app-dir is not supported any more"*) and refuses a public
directory outside it, so every package path is below the project path. It happens
when the project path was moved out from under the packages afterwards, with
`TYPO3_PATH_APP`.

## `seeder:list`

```bash
vendor/bin/typo3 seeder:list
vendor/bin/typo3 seeder:list -v
```

Prints identifier, title and providing extension of every set found, in discovery
order. `-v` adds the base path of the set.

| Exit code | Meaning                                                                                             |
|-----------|-----------------------------------------------------------------------------------------------------|
| `0`       | The sets were listed — including the case that there is none, which is not an error.                |
| `1`       | A `config.yml` cannot be read or does not name itself, or an identifier is provided more than once. |

An installation nobody ships seed data for is the normal state of most
installations, so an empty listing says so and succeeds. A duplicated identifier
does not: the sets cannot be told apart, so neither this command nor an import
may pick one of them.

The duplicate report is written as plain lines rather than as a `SymfonyStyle`
block, because a block is wrapped to the terminal width and an absolute path
broken over two lines is the one thing this report must not do.

## `seeder:import`

```bash
vendor/bin/typo3 seeder:import                      # asks, on a terminal
vendor/bin/typo3 seeder:import demo --dry-run
vendor/bin/typo3 seeder:import demo --base='https://example.com/'
```

### The order of the run

1. The **set is resolved** — asked for when no identifier was given and there is
   someone to ask, and answered with the near misses when the identifier names
   nothing.
2. The **descriptor is parsed and the scenario composed** into one
   `DataHandlerFactory`, before anything is written, so a set that cannot be
   written fails before its first row exists. This is also where the two
   cross references between the descriptor and the scenario are checked: a site
   naming a `rootPage` no `pages` entity declares, and a file reference naming a
   record no entity declares at all.
3. The **uids are checked** against the installation, per table - every uid the
   scenario declares *and* every one the factory assigned dynamically.
4. **Nothing is written on a dry run.** The run stops here and reports what the
   first three steps found.
5. The **backend user is authenticated**, and refused unless it is an admin.
6. **Files, records, file references and site configurations** are written, in
   that order, because each needs the uids of the one before it. The reference
   pass is separate rather than folded into the record pass for a reason that
   fails silently otherwise; see
   [The file reference pass](../architecture/seeding.md#the-file-reference-pass).

Both a dry run and a real run report the references as a line of their own -
`File references to attach: N` and `File references attached: N` - next to the
file and site configuration lines.

### Options

| Option             | Value  | Effect                                                                                                       |
|--------------------|--------|--------------------------------------------------------------------------------------------------------------|
| *(argument)*       | string | The set to import. Asked for on a terminal when omitted; without one, the sets are listed and the run stops. |
| `--dry-run`        | flag   | Parse, validate and check the set, report what an import would do, write nothing.                            |
| `--force`          | flag   | Import although the set suggests uids the installation already uses. See below.                              |
| `--root-page`      | uid    | The page the top level records are written below. `0`, the default, is the page tree root.                   |
| `--base`           | URL    | Overrides the `base` of every site configuration the set writes.                                             |
| `--no-site-config` | flag   | Skip the site configurations the set declares.                                                               |
| `-v`               | flag   | Adds the declared-uid-to-written-uid table to the result, and the suggested uids to a dry run.               |

`--root-page` is verified to exist before anything is written: DataHandler
happily writes a record below a page that is not there, and the result is a page
tree that is in the database and in no tree. Restrictions are removed for that
check — a hidden page is a perfectly good place to seed below.

It rewrites the `pid` of the **top level items** of the scenario and of nothing
else. Which items those are, and why the transformation sits there rather than
on the entity defaults or on the built data map, is on
[Seed definitions](seed-definitions.md#--root-page).

`--base` wins over the `base` of the definition, which wins over the one of the
template. Each of the two overrides is written by someone who knows more about
the instance than the layer below it, which is what lets one set be imported into
several installations.

`--no-site-config` is deliberately **not** the same as a set that declares no
site: the suppression of the automatic `autogenerated-<uid>` configuration is
unconditional, so skipping the declared ones is exactly the case where the
uncovered site roots have to be reported — and they are.

### Uid collisions and `--force`

A scenario suggests the uid of **every** record it writes, which is what makes a
seeded page tree reproducible - the ones an entity declares as `id`, and the
ones the factory assigned from its dynamic counter at 10000 upwards. There is no
"let the database decide" mode to fall back on.
`UidCollisionDetector` asks, per table and per uid,
whether the installation already uses one of them, and the refusal names the
records in the way — with their label, because *"pages:1, pages:2"* tells nobody
whether that is a page tree worth keeping and *"pages:1 (Company site)"* does.

Two properties of that check are deliberate:

- **It is not an emptiness check.** The command this extension was extracted from
  refused whenever a page with uid 1 existed, which is wrong in both directions: a
  set suggesting uid 200 everywhere was refused although nothing collided, and a
  set suggesting `tt_content:1` was imported although that content element was
  taken.
- **A deleted record occupies its uid.** The query runs with every restriction
  removed. `uid` is the primary key and a row flagged `deleted = 1` is still a
  row, so an insert with that uid fails — and what a restriction set would hide
  here is exactly the case that is hardest to explain afterwards.

`--force` does not skip the check; it decides what happens to its result. Every
uid of a **table something collides in** is dropped from the suggestions, and
those records are written with the next free uid. A table nothing collides in
keeps every uid the set declares. Nothing that is in the way is deleted or
changed.

Dropping only the colliding suggestion and keeping the others of the same table
looks more careful and is not: the record that lost its suggestion is written with
the next free uid, and the next free uid may be one the set suggests for a later
record of that table — which does not write that record somewhere else, it fails
its `INSERT` on the primary key. Giving up a whole table cannot run into that,
because a table nothing forces a uid in is written by the auto increment alone.

**`--force` is refused for a set that declares sites and collides in `pages`.**
A site names its root page by uid, and giving up the `pages` suggestions writes
that page under a different one - the site would point at a page the run never
created, or worse at somebody else's. The refusal exits `EXIT_UID_COLLISION`
like the unforced case, and says to import the set with `--no-site-config` or to
free the uids instead.

### Exit codes

A caller scripting this command has to be able to tell *"that set does not
exist"* from *"that set would overwrite something"*, and `1` for everything
cannot. The codes are part of the interface, and the help output carries them as
well.

| Code | Constant                  | Meaning                                                                                                                |
|------|---------------------------|------------------------------------------------------------------------------------------------------------------------|
| `0`  | `Command::SUCCESS`        | The set was imported, or the dry run found nothing to complain about.                                                  |
| `2`  | `EXIT_INVALID_INPUT`      | No identifier and no terminal to ask on, or an option value that cannot be used.                                       |
| `3`  | `EXIT_UNKNOWN_SET`        | No active extension provides a set of that identifier — or none at all.                                                |
| `4`  | `EXIT_UNRESOLVABLE_SET`   | The identifier is provided more than once, or a `config.yml` in this installation cannot be read.                      |
| `5`  | `EXIT_INVALID_DEFINITION` | The set was found and is not a valid seed definition, scenario files included.                                         |
| `6`  | `EXIT_UID_COLLISION`      | The set suggests uids this installation already uses. The only failure `--force` overrides.                            |
| `7`  | `EXIT_NO_ADMIN_USER`      | There is no admin backend user to write as.                                                                            |
| `8`  | `EXIT_SEEDING_FAILED`     | The writing itself failed. Nothing that gets this far is the caller's fault, which is why it is one code and not five. |

`2` is Symfony's own `Command::INVALID`, because that is exactly what it means.

### Being asked, and being answered

Without an identifier the command asks which set to import — the list of sets is
right there in the question. Without a terminal — a deployment script, a
pipeline, a hook — there is nobody to ask, so the sets are printed and the caller
is told what to pass. Guessing a set to import is the one thing this command must
never do.

An identifier that resolves to nothing is answered with the near misses, because
a typo is the overwhelmingly common reason and the set that was meant is usually
one edit away. The threshold is two edits plus one for every three characters
beyond six, and a substring hit counts whatever its distance — so `demo` is not
`hero`, `demo-content` still matches `demo-contents`, and a half remembered
identifier is found.

## See also

- [Seed definitions](seed-definitions.md) — the descriptor and the scenario format the commands read
- [Seeding](../architecture/seeding.md) — what happens between parsing and the database
- [Site configurations](../architecture/site-configuration.md)
- [Development environment](environment.md)
