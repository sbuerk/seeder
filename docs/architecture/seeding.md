# Seeding

How a seed set becomes records in the database, and why each step is the way it
is. The format a set is written in has its own page —
[Seed definitions](../development/seed-definitions.md) — and the command surface
around it is described in [Seed sets and the CLI](../development/seed-sets.md).
This page is about the mechanism.

Everything here was established by reading the core on disk, on TYPO3 v13.4 and
v14.3, and every claim names the file it came from so the next reader can check
it instead of trusting it. The class docblocks carry the same citations with
line numbers; line numbers move, the cited method names do not.

## The pipeline

One import runs in this order, and the order is a consequence of what each step
needs from the one before it:

| Step                      | Class                       | Needs from the previous step                             |
|---------------------------|-----------------------------|----------------------------------------------------------|
| Discover the set          | `SeedSetRepository`         | —                                                        |
| Parse the definition      | `SeedDefinitionParser`      | the path of the `config.yml`                             |
| Build the data map        | `DataMapFactory`            | the parsed definition and the `sys_file` uids            |
| Check the suggested uids  | `UidCollisionDetector`      | the `suggestedUids` of the data map                      |
| Copy the files            | `FileSeeder`                | —                                                        |
| Write the records         | `RecordSeeder`              | the `sys_file` uids, to describe a file reference at all |
| Write the file references | `RecordSeeder`, second pass | the written uids, because `uid_foreign` resolves nothing |
| Write the sites           | `SiteConfigurationSeeder`   | the written uids, for `rootPageId`                       |

The data map is built **before** anything is written, and it is built by the
command as well as by the seeder. That is deliberate: building it is what
validates the parts of a set only the factory can see — a record referencing a
file the set does not ship, for instance — so a set that cannot be written fails
before its first row exists rather than halfway through, and a dry run does the
same work a real run does minus the writing.

## Why it goes through DataHandler

Writing rows directly would be faster and would produce something that is not a
page tree. `DataHandler` is what generates slugs, applies the TCA defaults and
`eval` rules, computes `sorting`, resolves relations, updates the reference
index and flushes the caches. A seeder writing SQL has to reimplement all of
that, and gets it subtly wrong — in ways that are visible in the frontend weeks
later and not in the seed definition.

The price is that DataHandler is built for a backend form, and several of its
behaviours are wrong for a seed. Each of the sections below is one of them.

## The admin user requirement

`RecordSeeder::seed()` refuses a backend user that is not an admin, and the
refusal is not a permission policy of this extension. `DataHandler::insertDB()`
evaluates a suggested uid behind `$this->BE_USER->isAdmin() &&` — *"As a
security measure this feature is available only for Admin Users (for now)"* —
and says nothing when the check fails.

A non-admin run therefore writes the page tree with whatever uids happened to be
free, reports success, and every site configuration, TypoScript condition or
test pointing at a root page by its number is wrong. That is exactly the class of
failure this extension exists to avoid, so it is turned into a refusal.

`ImportSeedCommand` asks the same question before the seeder does, which is what
turns the exception into a sentence and an exit code of its own
(`EXIT_NO_ADMIN_USER`). The CLI application creates the `_cli_` user object
without logging it in; `Bootstrap::initializeBackendAuthentication()`
authenticates it and creates the record on first use, with `admin = 1`
(`Core\Authentication\CommandLineUserCreation`). An already authenticated user
is left alone, which is what lets a test drive the command as a user of its
choosing.

## Declaration order, and the negative pid

DataHandler places a new record at the **top** of its parent. Records created in
the order they are declared therefore come out reversed, and nothing says so —
the tree exists, it is simply upside down.

The convention DataHandler offers instead is a negative `pid`, meaning "directly
after this record": it strips the sign, resolves the placeholder and hands the
signed value to `resolveSortingAndPidForNewRecord()`. `DataMapFactory` uses it,
so only the **first** sibling addresses its parent and every following one
addresses the sibling before it.

The predecessor is tracked **per table**. A negative pid names a record of the
same table, and the children of a page are a mix of sub pages, content elements
and records of other tables — pointing a content element at the page before it
would place it somewhere else entirely. Records of three tables on one page
therefore do not disturb each other's sorting.

An **inline child gets a plain pid**, never the negative hint. Its order comes
from the comma separated list of placeholders written into the parent's field
and from nothing else, because that list is what DataHandler walks when it
numbers the relation.

## Records are seeded visible

DataHandler creates a record hidden. That is right for an editor, who wants to
finish a page before it is public, and wrong for a seed: the tree would exist,
the frontend would render nothing, and nothing would say why.

`DataMapFactory` therefore writes `hidden = 0` unless the definition declares
otherwise — `$values += ['hidden' => 0]`, so a definition asking for
`hidden: 1` still gets a hidden record. The default was verified by removing the
line and watching the functional test find `hidden = 1`.

`pages` records additionally get `doktype`, `l10n_parent` and `sys_language_uid`
written explicitly, again only where the definition declares none. The reason is
**not** that a warning would otherwise fire — that was checked and is not the
case on either version:

| Field              | Where its value comes from without the seeder                                                                     |
|--------------------|-------------------------------------------------------------------------------------------------------------------|
| `doktype`          | the shipped `pages` TCA, which declares `'default' => (string)PageRepository::DOKTYPE_DEFAULT` on both versions   |
| `l10n_parent`      | not a shipped column at all; `TcaEnrichment::enrichTransOrigPointerField()` materialises it with `'default' => 0` |
| `sys_language_uid` | nothing — it is materialised as `type => language`, and `LanguageFieldType::hasDefaultValue()` returns `false`    |

Only the third has no default, and DataHandler covers it with
`addDefaultPermittedLanguageIfNotSet()`, which takes the first language the site
of the target page offers rather than the default language.

The seeder writes all three because **a seed should say what it writes**.
Inheriting `doktype` from whatever the TCA of an installation happens to default
to makes the same definition produce different records in two installations,
which is the opposite of what a seed is for. There is a second, smaller reason:
`Core\Hooks\CreateSiteConfiguration` reads `$fieldValues['l10n_parent']`
unguarded on v13.4 and `?? 0` on v14.3, so on v13.4 exactly one TCA override
removing that enrichment default separates a seeded page from an
`Undefined array key` warning — which this test suite turns into a failure.

The functional test asserts that the hook path is **walked**, not merely that no
warning appeared. "No warning" is not evidence when the core supplies the value
itself; a test that walks the reads goes red when a core version starts reading
a field that is not written here.

## Suggested uids need both halves

A declared `uid` is a *suggestion*, and DataHandler reads it from two places at
once:

- the `uid` column of the **data map row**, which is where `insertDB()` takes the
  suggestion from (it then drops the column again — *"Do NOT insert the UID
  field, ever!"* — so putting it there cannot write a uid by itself);
- `DataHandler::$suggestedInsertUids`, keyed **`"<table>:<uid>"`** and not by the
  placeholder, because that is the key `insertDB()` looks up.

Getting either half wrong fails silently: DataHandler assigns the next free uid,
the run reports whatever it got, and the result is right only as long as
declaration order happens to equal insertion order. The regression test declares
uid `4711` for that reason — a number that cannot be reached by counting.

A suggestion is not a demand in the other direction either. Leaving it in for a
uid another row already holds does not write the record elsewhere; it makes the
`INSERT` fail on the primary key, because DataHandler forces the uid into the
field array. What that looks like was established by removing the collision check
and watching it: on v13.4 the failed insert returns `null`, the `pages` branch of
`process_datamap()` hands that `null` to `addDefaultPermittedLanguageIfNotSet()`
and a `TypeError` comes out — not even the logged SQL error one would expect.
Hence `UidCollisionDetector`, and hence `--force` dropping suggestions instead of
overriding the refusal; see
[Seed sets and the CLI](../development/seed-sets.md#uid-collisions-and---force).

## The placeholder carries no underscore

A record is handed to DataHandler under the placeholder
`NEW<table without underscores>-<identifier>` — `NEWttcontent-home`, not
`NEWtt_content_home`. The dash and the stripped underscores are load bearing.

`DataHandler::processRemapStack()` resolves a relation value like this:

```php
if (!str_contains($value, '_')) {
    $affectedTable = $tcaFieldConf['foreign_table'] ?? '';
    $prependTable = false;
} else {
    $parts = explode('_', $value);
    $value = array_pop($parts);
    $affectedTable = implode('_', $parts);
    $prependTable = true;
}
$value = $this->substNEWwithIDs[$value] ?? '';
```

A value containing an underscore is read as the `<table>_<uid>` form the backend
writes for a group field. `NEWtt_content_home` is therefore taken apart into a
table `NEWtt_content` and an id `home`, `substNEWwithIDs['home']` does not exist,
and `?? ''` writes the relation **empty — with an empty error log**. Nothing
reports it.

The branch is unchanged between v13.4 and v14.3. It is found by searching
`processRemapStack()` for the comment *"Replace relations to NEW...-IDs in field
value"*, which is the line above it — a search that keeps working when the line
numbers move.

Two consequences reach the format:

- `SeedDefinitionParser` rejects an identifier that is not
  `/^[A-Za-z0-9][A-Za-z0-9-]*$/`, so a definition that would seed an empty
  relation is refused with a message instead;
- the placeholders the seeder invents itself follow the same rule —
  `NEWsysfilereference-<n>` for a file reference.

Site identifiers are a separate namespace and **may** contain underscores: one
never reaches a DataHandler placeholder. What that pattern guards is the
directory name below `config/sites/`.

## File references take a second pass

`sys_file_reference.uid_foreign` is a plain integer column, not a relation
DataHandler resolves. A `NEW…` placeholder written there stays a string, is read
as `0`, and the reference silently belongs to record 0. The records therefore
have to exist before their references can be described, which is what makes this
a second `process_datamap()` run rather than more rows in the same data map.

`DataMapFactory` collects rather than writes: what leaves it is a flat list of
what to write once the uids are known, and `RecordSeeder::attachFileReferences()`
turns that into the second data map.

Two things are written per reference, and the second is the one that is easy to
leave out:

1. the `sys_file_reference` row, with `uid_local`, `uid_foreign`, `tablenames`,
   `fieldname` and `pid` — written **on top of** the declared fields, so a
   definition cannot detach a reference from the record declaring it by naming
   `uid_foreign` itself;
2. **the counter field of the parent**, as an update keyed by the parent's uid
   carrying nothing but the comma separated list of reference placeholders.

Without the second, DataHandler sees no relation to resolve,
`RelationHandler::writeForeignField()` never runs, and every seeded reference
keeps a `sorting_foreign` of `0`.

**What that costs is invisible, which is why it survived a long time in the code
this extension was extracted from.** `FileRepository::findByRelation()` selects
by `uid_foreign`, `tablenames` and `fieldname` and never reads the counter
column, so the images appear either way. It *orders* by `sorting_foreign` — so
all that is lost is the order of a multi file relation, to whatever the database
feels like returning, which is stable enough in testing to look correct. The
functional test asserts the column for exactly that reason.

The `pid` of a reference is the page of its level, never the record's own `pid`:
that one may be the negative "insert after" hint, which is a sorting instruction
and not a page.

## `isImporting`, and what it costs

Both passes set `DataHandler::$isImporting`. It is the flag that suppresses the
`autogenerated-<uid>` site configuration TYPO3 writes for every new page on the
page tree root or carrying `is_siteroot` — `Core\Hooks\CreateSiteConfiguration`,
whose early return ends in `|| $dataHandler->isImporting`. It is the same flag
`EXT:impexp` sets for the same reason, in six places, and the core has a
functional test named `importDoesNotCreateSiteConfigurationWhenDisabled()` for
it. It is not deprecated: searching the changelogs of 13.4 and 14.3 for
`isImporting`, `CreateSiteConfiguration` and `processDatamapClass` returns
nothing.

It is a **public flag with nine consumers**, so adopting it blind is not
acceptable. Enumerated on both versions — the lists are identical — the consumers
outside `impexp` are:

| Consumer                                               | Effect                                                                             | Verdict        |
|--------------------------------------------------------|------------------------------------------------------------------------------------|----------------|
| `Core\Hooks\CreateSiteConfiguration`                   | no automatic site configuration                                                    | the point      |
| `Backend\Hooks\DataHandlerAuthenticationContext`       | skips sudo mode and authentication context evaluation                              | neutral        |
| `Core\Resource\Security\FilePermissionAspect`          | lets `sys_file` be written through DataHandler at all                              | neutral        |
| `Core\Resource\Security\FilePermissionAspect`          | skips `usesDisallowedFileMount()` for `sys_file_reference` and `sys_file_metadata` | **beneficial** |
| `Core\Resource\Security\FileMetadataPermissionsAspect` | allows a `sys_file_metadata` record with an empty `file`                           | neutral        |
| `Core\Hooks\UpdateFileIndexEntry`                      | skips re-indexing after a new `sys_file_metadata` record                           | neutral        |
| `Core\Hooks\BackendUserPasswordCheck`                  | no generated password and `autogenerated-<md5>` username for a `be_users` record   | **caveat**     |
| `DataHandler`                                          | the flag is handed on to the copy handler                                          | neutral        |

The neutral ones are neutral for a reason each: the authentication context hook
returns immediately without a backend request and seeding runs on the CLI; files
are seeded through the storage API rather than through DataHandler; no
`sys_file_metadata` record is written; nothing is copied.

The **beneficial** one is why the flag would be worth setting even without the
site configuration. Without it, a reference to a file in a folder the backend
user has no file mount for has its entire field array nulled and an error
logged — which is exactly the situation a seed run from the command line is in.
That is also why the second pass sets the flag as well: an admin passes the check
today, so it is a guard rather than a fix, but two passes of one import declaring
different things is an inconsistency waiting to be found the hard way.

The **caveat** is real and has to be stated in the user documentation: a seed set
writing a `be_users` record has to declare `username` and `password` itself, and
gets a user that cannot log in if it does not.

The flag is set **unconditionally**, not only for a definition that declares
`sites`. An `autogenerated-<uid>` site is never what a seed wanted, and a set
declaring no site is a set whose site configuration comes from elsewhere — not an
invitation to invent one. What that leaves behind is reported rather than
silently accepted; see
[Site configurations](site-configuration.md#the-uncovered-site-roots-report).

## Errors are not logged, they are raised

DataHandler reports a refused write by logging it and carrying on, so a run that
wrote half a page tree looks exactly like one that wrote all of it.
`RecordSeeder::assertNoErrors()` turns a non-empty `errorLog` into a
`SeedingFailedException` after each pass, which is what makes the difference
visible.

The same reasoning applies one level up, in the parser:
`YamlFileLoader::processImports()` catches its exceptions per import and reports
them to a logger. For a site configuration that is a reasonable trade — the site
still loads, minus an optional include. For a seed definition it is data loss, so
`ThrowOnErrorLogger` raises the report back into an exception. Only error level
and above, so a future core version logging something informational from the
loader does not turn a working definition into a failure.

## The layout of `Classes/Seeding/`

| Directory       | What lives there                                                                                                                                                    |
|-----------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `Definition/`   | The parsed definition and its parts: `SeedDefinition`, `SeedRecord`, `SeedFile`, `SeedFileReference`, `SeedSiteConfiguration`. Data, `#[Exclude]`, no dependencies. |
| `Parser/`       | `SeedDefinitionParser` and the `ThrowOnErrorLogger` it hands to `YamlFileLoader`.                                                                                   |
| `DataHandling/` | Everything that writes: `DataMapFactory`, `RecordSeeder`, `FileSeeder`, `SiteConfigurationSeeder`, plus `UidCollisionDetector` and the two result objects.          |
| `Exception/`    | `SeedingException` and its five subclasses, so a caller can tell "unknown set" from "invalid definition".                                                           |
| root            | `SeedSetRepository` and the `SeedSet` it returns — discovery, which is deliberately not parsing.                                                                    |

Two properties of that layout are worth naming because they are easy to erode:

- **`Definition/` depends on nothing.** No service, no TYPO3 API, no other part
  of this extension — which is what would let the definition model be extracted
  into a package of its own later.
- **Discovery does not parse.** `SeedSetRepository` reads three keys with
  `Yaml::parseFile()` and stops. Parsing every set in full to show a title would
  make `seeder:list` as fragile as the least well maintained set in the
  installation; see
  [Seed sets and the CLI](../development/seed-sets.md#discovery-reads-metadata).

A field needs **no support anywhere in this pipeline to be seedable**. Apart from
`pid` and the defaults above, every declared value is copied into the data map
untouched. That is a design decision made of the absence of a branch — a factory
special-casing a field it does not have to will special-case the next one too —
and a test is what keeps it true.

## See also

- [Seed definitions](../development/seed-definitions.md) — the format itself
- [Seed sets and the CLI](../development/seed-sets.md) — discovery, ordering, the commands
- [Site configurations](site-configuration.md) — what runs after the records
- [Class design](class-design.md) — why the definition objects carry `#[Exclude]`
- [Dependency injection](dependency-injection.md)
