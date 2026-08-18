# Seed definitions

The authoritative specification of the YAML format. `SeedDefinitionParser` is
the implementation; where this page and the parser disagree, the parser is what
ships and the disagreement is a bug in one of them.

The reasoning behind the format is on
[Seeding](../architecture/seeding.md) — this page says what is accepted, at
which level, and what happens when it is not.

## The one property everything else follows from

**Everything that is not a structural key on that level is a field of the
record, and is written verbatim.** A field needs no support in the seeder to be
seedable: no allow list, no mapping table, no `switch`. The absence of that
branch is deliberate, and a unit test keeps it true.

The cost is that a typo in a field name is a field, not an error. That is
accepted for a record — the seeder cannot know the TCA of every table — and
explicitly **not** accepted where a level has a closed set of keys, which is the
top level of the definition and a `sites` entry. There, an unknown key is
refused: accepting `page:` instead of `pages:` silently is how an import reports
success and writes nothing.

## Where a definition lives

A seed set is a directory `Configuration/Seeder/<name>/` in any active package,
with `config.yml` as its entry file:

```
Configuration/Seeder/demo/
├── config.yml               entry file, and the only mandatory one
├── Pages.yaml               optional, pulled in through "imports"
├── Files/
│   └── placeholder.svg      resources named by "files"
└── Sites/
    └── main/
        ├── config.yaml      site configuration template
        └── settings.yaml    optional site settings
```

Every relative path inside a set — a file `source`, a site `template`, an
`imports` resource — resolves against the directory holding the **entry file**,
not against the file declaring it. That is what lets a set be moved or renamed
without touching its paths. `EXT:` paths are accepted everywhere a path is and
ignore the base directory.

Discovery is described in [Seed sets and the CLI](seed-sets.md).

## Set level

```yaml
identifier: demo
title: 'Demo page tree'
description: 'Pages, content and a site configuration.'

imports:
  - { resource: Pages.yaml }

files: []
pages: []
sites: []
```

| Key           | Required | Type   | Default | Meaning                                                                                                                                      |
|---------------|----------|--------|---------|----------------------------------------------------------------------------------------------------------------------------------------------|
| `identifier`  | yes      | string | —       | Globally unique across all active packages. Declared, never derived from the directory name — a derived identifier makes a collision silent. |
| `title`       | yes      | string | —       | Shown by `seeder:list`.                                                                                                                      |
| `description` | no       | string | `''`    | Long text. Carried on the set and the definition; no command prints it today.                                                                |
| `imports`     | no       | list   | `[]`    | Further YAML files merged into this one. Consumed by `YamlFileLoader` before the parser sees the definition.                                 |
| `files`       | no       | list   | `[]`    | Files copied into a storage before any record is written.                                                                                    |
| `pages`       | no       | list   | `[]`    | The top level records of the set. They are `pages` records.                                                                                  |
| `sites`       | no       | list   | `[]`    | Site configurations written after the records.                                                                                               |

This list is **closed**. Any other key is refused, and the message names the
known ones.

`description:` with nothing behind it decodes to `null` and is treated exactly
like an absent key.

A definition that parses and produces no record at all is refused when it is
imported, not when it is parsed — the message is *"contains no records"*.

## Structural keys, and where they are structural

| Key          | Structural on                  | An ordinary field on |
|--------------|--------------------------------|----------------------|
| `identifier` | every record                   | —                    |
| `uid`        | every record                   | —                    |
| `children`   | every record                   | —                    |
| `content`    | every record                   | —                    |
| `files`      | every record                   | —                    |
| `inline`     | every record                   | —                    |
| `table`      | an `inline` or `records` child | every other record   |
| `records`    | a record of `pages`            | every other record   |

The last two rows are the reason this is decided per level rather than once:

- `tt_content` and `pages` both have real columns whose name begins with
  `table`, so `table` can only be structure where there is no other way to know
  the table — which is an `inline` child (the alternative would be reading
  `config.foreign_table` out of the parent's TCA) and a `records` child (where
  there is no parent field at all).
- `tt_content` has a real `records` column — the one the *Insert records*
  element writes `tt_content_<uid>` into. So `records` is structure only on a
  record whose **resolved table** is `pages`. Note *resolved*: a page declared
  below `records` is still a page and may carry `records` of its own.

## Record level

```yaml
pages:
  - identifier: home            # required, unique across the whole definition
    uid: 1                      # optional, a suggested uid, positive integer
    title: 'Demo'               # any non-structural key is a field
    slug: '/'
    is_siteroot: 1
    files:                      # file references, per field
      media:
        - placeholder
        - identifier: portrait
          alternative: 'A placeholder graphic'
    content:                    # tt_content records on this page
      - identifier: home-heading
        CType: header
        header: 'A frontend to look at'
    records:                    # records of any table on this page
      - identifier: category-news
        table: sys_category
        title: 'News'
    inline:                     # children of a relation, per parent field
      tx_example_items:
        - identifier: item-docs
          table: tx_example_item
          title: 'Documentation'
    children:                   # sub pages
      - identifier: about
        title: 'About'
        slug: '/about'
```

A field value has to be a **scalar or null**. There is no support for writing an
array into a column, because there is no column that takes one — a relation is
expressed by `inline`, by `files`, or by writing the uid of the target into the
relation field like any other value.

### Validation

| Rule                                                                      | On violation                               |
|---------------------------------------------------------------------------|--------------------------------------------|
| `identifier` is a non-empty string                                        | refused, naming the level it was found on  |
| `identifier` matches `/^[A-Za-z0-9][A-Za-z0-9-]*$/`                       | refused, with the DataHandler reason       |
| `identifier` is unique across the **whole** definition, `inline` included | refused, naming the duplicate              |
| `uid`, where present, is an integer ≥ 1                                   | refused                                    |
| an `inline` or `records` child declares `table`                           | refused, stating that it is never inferred |
| `content`, `children`, `records` are lists                                | refused, naming the key and the record     |
| `inline` and `files` are maps of field name to a list                     | refused, naming the field                  |
| a field name is a string                                                  | refused                                    |
| a field value is scalar or null                                           | refused, naming the field                  |

Record identifiers and file identifiers are **separate namespaces**: a file may
be called `home` while a page is, and neither shadows the other.

Nothing validates a `uid` against the other `uid` values of the definition, and
nothing validates a table name or a field name against the TCA. The first is a
gap worth knowing about; the second two are what makes a set portable across
installations that have different extensions loaded, and DataHandler reports
both at import time.

### The identifier character set is not cosmetic

An identifier ends up inside the placeholder DataHandler resolves relations by —
`NEW<table without underscores>-<identifier>`. A placeholder containing an
underscore is read as the `<table>_<uid>` form the backend writes for a group
field and is split there, after which the relation is written **empty, with an
empty error log**. Rejecting the identifier is what keeps that from happening
silently. The full quotation of the branch is on
[Seeding](../architecture/seeding.md#the-placeholder-carries-no-underscore).

## Nesting

| Key        | Produces                                                                                           |
|------------|----------------------------------------------------------------------------------------------------|
| `children` | `pages` records with the declaring page as their `pid`                                             |
| `content`  | `tt_content` records with the declaring page as their `pid`                                        |
| `records`  | records of the declared `table` with the declaring page as their `pid`                             |
| `inline`   | records tied to the parent through the named field; their `pid` is the page the **parent** sits on |

`children`, `content` and `records` join the same sibling chain of the declaring
page, and declaration order is preserved through the negative-`pid` convention
**tracked per table** — so pages, content elements and records of three further
tables on one page do not disturb each other's sorting.

An inline child's `pid` is the page its parent sits on, never the parent itself:
a relation is not a containment, and writing the parent's placeholder there would
put content records on a content record. Its order comes from the parent's field
value, which the seeder writes as the comma separated list of the children's
placeholders in declaration order.

Inline nests arbitrarily deep, and an inline child may carry `files` and
`inline` of its own.

An inline child must **not** carry `children`, `content` or `records`. Those
describe what sits on a page, and an inline child is not a page. The parser
accepts them today and `DataMapFactory` never writes them — it walks the
`children` of a top level or nested record and the `inline` of any record, and an
inline child's page-style children are reached by neither. Nothing reports it,
which makes this the one place in the format where a declaration is silently
ignored; treat it as a defect to be fixed rather than as behaviour to rely on.

## Files

```yaml
files:
  - identifier: placeholder             # required, unique among the files
    source: 'Files/placeholder.svg'     # required; relative to the set, or EXT:
    folder: 'seed-files'                # optional, default the storage root
    name: 'placeholder.svg'             # optional, default the source basename
    storage: 1                          # optional, default the default storage
```

| Key          | Required | Type   | Default                    |
|--------------|----------|--------|----------------------------|
| `identifier` | yes      | string | —                          |
| `source`     | yes      | string | —                          |
| `folder`     | no       | string | `/`, the storage root      |
| `name`       | no       | string | the basename of the source |
| `storage`    | no       | int    | the default storage        |

A `folder` that does not exist is created. The file is copied into the storage
through the storage API, which is what indexes it — a file copied into
`fileadmin/` with `cp` exists on disk and does not exist for TYPO3, so nothing
can reference it. An existing file of the same name is **replaced**.

`folder`, `name` and `storage` fall back to their default when they carry a value
of the wrong type, rather than refusing the definition. A missing source file
*is* refused, naming both the declared path and the absolute path it resolved
to.

### References

A record points at a seeded file per field:

```yaml
files:
  media:
    - placeholder
    - identifier: portrait
      alternative: 'A portrait placeholder'
      description: 'Shown as the caption'
```

The short form is the bare identifier of a declared file. The long form names it
under `identifier` and carries the fields of the `sys_file_reference` record
itself — `alternative`, `title`, `description`, `link`, `crop`: what an editor
fills in on a file relation. They live on the reference rather than on the file,
which is what lets the same image carry a different alternative text in two
places. A field the TCA of `sys_file_reference` does not know is dropped by
DataHandler without a word.

`uid_local`, `uid_foreign`, `tablenames`, `fieldname` and `pid` are written by
the seeder and always win over a declared value, so a definition cannot detach a
reference from the record carrying it.

A field declared with an **empty list creates no reference and leaves the field
alone**. Seeding writes; an empty declaration is not an instruction to clear a
relation.

Referencing a file the definition does not declare is refused — before anything
is written, because the data map is built first.

## Site configurations

```yaml
sites:
  - identifier: main                    # required, becomes config/sites/<identifier>/
    rootPage: home                      # required, the *seed* identifier of a page
    template: 'Sites/main'              # optional, default Sites/<identifier>
    base: 'https://example.com/'        # optional, overrides the template's base
```

| Key          | Required | Type   | Default              | Notes                                                                     |
|--------------|----------|--------|----------------------|---------------------------------------------------------------------------|
| `identifier` | yes      | string | —                    | Matches `/^[A-Za-z0-9][A-Za-z0-9_-]*$/`, unique within the definition.    |
| `rootPage`   | yes      | string | —                    | Has to name a record of the definition, and that record has to be a page. |
| `template`   | no       | string | `Sites/<identifier>` | Directory, relative to the set or `EXT:`.                                 |
| `base`       | no       | string | the template's own   | `null` leaves the template alone, which is not the same as an empty base. |

This list of keys is **closed** as well: a site is configuration rather than a
record, nothing here is written verbatim, and an unknown key can only be a
mistake.

A site identifier **may** contain underscores, unlike a record identifier: it
never reaches a DataHandler placeholder. What its pattern guards is the directory
name below `config/sites/` — no separator, no `.` or `..`, nothing that has to be
escaped anywhere.

`rootPage` is validated against the records of the definition at parse time,
which is the difference between a message naming the unknown page and a site
written with a root page id of zero much later in the run. What the template
declares as `rootPageId` is always overwritten with the resolved uid.

The template directory and everything else about the writing side is described on
[Site configurations](../architecture/site-configuration.md).

## Imports

```yaml
imports:
  - { resource: Pages.yaml }
  - { resource: 'EXT:my_extension/Configuration/Seeder/shared/Content.yaml' }
```

`imports` is handled by `TYPO3\CMS\Core\Configuration\Loader\YamlFileLoader`, the
loader the core reads its own site configurations with. It resolves a resource
relative to the file declaring it, accepts `EXT:` paths, and **merges** an
imported list into the importing one instead of replacing it — which is what a
set split over several files needs, and it means this extension requires nothing
beyond `typo3/cms-core`.

Two deliberate deviations from how the core calls it:

- **Placeholders are switched off.** A seed definition is content, not
  configuration: `%` occurs in pairs in perfectly ordinary titles and body texts,
  and a `%…%` fragment that happens to name a key of the definition would be
  substituted with that key's value. Seeded content has to arrive in the database
  as it was written, and environment variables have no business being
  interpolated into it. Note that this differs from a **site template**, where
  `%env(…)%` *is* meaningful and is deliberately left unresolved for the instance
  to evaluate.
- **A failing import raises** instead of being logged. The loader catches its
  exceptions per import and reports them to its logger; for a seed definition
  that is data loss — a typo in a resource path means those pages are silently
  not seeded and the import reports success.

An imported file carries the same keys as the entry file. The three metadata keys
`identifier`, `title` and `description` are the exception: they have to be
declared in `config.yml` itself, because discovery reads them without the
importing loader. That is not a restriction worth regretting — the identity of a
set is the one thing that cannot sensibly live somewhere else.

## What the seeder writes by itself

| Column                                                       | On                     | Rule                                                                 |
|--------------------------------------------------------------|------------------------|----------------------------------------------------------------------|
| `pid`                                                        | every record           | Structure. Never taken from the definition.                          |
| `hidden`                                                     | every record           | Defaults to `0`; a declared value wins.                              |
| `doktype`, `l10n_parent`, `sys_language_uid`                 | `pages` records        | Default to `1`, `0`, `0`; a declared value wins.                     |
| `uid`                                                        | records declaring one  | Suggested, and dropped again before the insert.                      |
| `sorting`                                                    | every record           | Computed by DataHandler out of the negative-`pid` convention.        |
| `uid_local`, `uid_foreign`, `tablenames`, `fieldname`, `pid` | `sys_file_reference`   | Structure. Always win over a declared value.                         |
| the counter field of a file relation                         | the referencing record | Written in the second pass, which is what numbers `sorting_foreign`. |

Everything else in a row comes from the definition or from the TCA of the
installation, in that order.

## Deliberate limitations

| Not covered                             | Why                                                                                                                                                        |
|-----------------------------------------|------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `sys_file_metadata`                     | An alternative text describes what an image means *in this place*, which is a property of the reference, not of the file.                                  |
| Translations and `l10n_parent` chains   | Both fields are writable as ordinary fields; a first-class translation construct is a later feature, not a workaround.                                     |
| Updating an existing tree               | Seeding writes. It does not reconcile an existing tree against a definition, and nothing here is idempotent.                                               |
| Explicit MM relation construction       | Not needed: a relation is expressed by writing the target into the relation field, and DataHandler writes the MM rows into a table the seeder never names. |
| A `be_users` record without credentials | `isImporting` disables the generated password and username, so such a record cannot log in. Declare `username` and `password`.                             |
| Deleting or overwriting anything        | An import refuses a uid collision and refuses an existing site identifier. There is no mode in which it removes data.                                      |

## See also

- [Seeding](../architecture/seeding.md) — why the format looks like this
- [Seed sets and the CLI](seed-sets.md) — discovery, ordering and the commands
- [Site configurations](../architecture/site-configuration.md)
- [Quality gates](quality-gates.md)
