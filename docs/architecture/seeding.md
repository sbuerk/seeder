# Seeding

How a seed set becomes records in the database, and why each step is the way it
is. The two formats a set is written in have their own page -
[Seed definitions](../development/seed-definitions.md) - the engine that reads
the scenario format has
[another](scenario-engine.md), and the command surface around both is described
in [Seed sets and the CLI](../development/seed-sets.md). This page is about the
mechanism between them.

Everything here was established by reading the core on disk, on TYPO3 v13.4 and
v14.3, and every claim names the file it came from so the next reader can check
it instead of trusting it. The class docblocks carry the same citations with
line numbers; line numbers move, the cited method names do not.

## The pipeline

One import runs in this order, and the order is a consequence of what each step
needs from the one before it:

| Step                     | Class                     | Needs from the previous step                              |
|--------------------------|---------------------------|-----------------------------------------------------------|
| Discover the set         | `SeedSetRepository`       | -                                                         |
| Parse the descriptor     | `SeedDefinitionParser`    | the path of the `config.yml`                              |
| Compose the scenario     | `ScenarioComposer`        | the parsed descriptor and its `scenarios` list            |
| Build the maps           | `DataHandlerFactory`      | the merged scenario settings                              |
| Check the suggested uids | `UidCollisionDetector`    | `getSuggestedIds()` of the factory                        |
| Copy the files           | `FileSeeder`              | -                                                         |
| Write the records        | `ScenarioSeeder`          | the factory, an admin backend user, the uids to hold back |
| Write the sites          | `SiteConfigurationSeeder` | the written uids, for `rootPageId`                        |

`ScenarioComposer` and `DataHandlerFactory` run **before** anything is written,
and they run in the command as well as in the seeder. That is deliberate:
composing is what validates everything only the factory can see - a duplicate
suggested uid, an item without `self`, a version variant declaring an `id` - so
a set that cannot be written fails before its first row exists, and a dry run
does the work a real run does minus the writing.

The writing itself is `DataHandlerWriter`, the second ported class: one
`process_datamap()` pass per workspace, then one `process_cmdmap()` pass per
workspace, with `NEW…` identifiers substituted by the uids the previous pass
produced. What `ScenarioSeeder` adds around it is the subject of the sections
below.

## Why it goes through DataHandler

Writing rows directly would be faster and would produce something that is not a
page tree. `DataHandler` is what generates slugs, applies the TCA defaults and
`eval` rules, computes `sorting`, resolves relations, updates the reference
index and flushes the caches. A seeder writing SQL has to reimplement all of
that, and gets it subtly wrong - in ways that are visible in the frontend weeks
later and not in the seed definition.

The price is that DataHandler is built for a backend form, and several of its
behaviours are wrong for a seed. Each of the sections below is one of them.

## The admin user requirement

`ScenarioSeeder::seed()` refuses a backend user that is not an admin, and the
refusal is not a permission policy of this extension. `DataHandler::insertDB()`
evaluates a suggested uid behind `$this->BE_USER->isAdmin() &&` - *"As a
security measure this feature is available only for Admin Users (for now)"* -
and says nothing when the check fails.

A non-admin run therefore writes the page tree with whatever uids happened to be
free, reports success, and every site configuration, TypoScript condition or
test pointing at a root page by its number is wrong. That is exactly the class
of failure this extension exists to avoid, so it is turned into a refusal.

It bites harder with the scenario format than it did with the old record format,
because **every** record of a scenario carries a suggested uid - a declared `id`
or a dynamic one from 10000 upwards. There is no subset of a scenario that would
survive a non-admin run intact.

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
the order they are declared therefore come out reversed, and nothing says so -
the tree exists, it is simply upside down.

The convention DataHandler offers instead is a negative `pid`, meaning "directly
after this record": it strips the sign, resolves the placeholder and hands the
signed value to `resolveSortingAndPidForNewRecord()`.
`DataHandlerFactory::setInDataMap()` uses it, so only the **first** sibling
addresses its parent and every following one addresses the sibling before it.

The predecessor is looked up per workspace, per table and per resolved page, in
`filterDataMapByPageId()`. A negative pid names a record of the same table, and
the children of a page are a mix of sub pages, content elements and records of
other tables - pointing a content element at the page before it would place it
somewhere else entirely.

**The back references are only resolved for `pages`.** That is upstream
behaviour, it is carried unchanged, and it is the single most consequential
property of the engine for anyone reading a seeded site: content elements on one
page come out in the order c1, c3, c2. It is pinned by a test and described in
[Sibling ordering only really works for pages](scenario-engine.md#sibling-ordering-only-really-works-for-pages).

A **language variant of a node entity** is given the same convention - a `pid`
of `-<identifier of the original>` - so a translated page sits next to its
original rather than at the top of the page tree.

## Nothing is defaulted for the scenario any more

The record format this extension started with wrote four columns on the seeder's
own initiative: `hidden = 0` on every record, and `doktype`, `l10n_parent` and
`sys_language_uid` on every page, each only where the definition declared none.
The reasoning was that *a seed should say what it writes* rather than inherit
whatever the TCA of an installation happens to default to.

The scenario format keeps that property and moves it: **the scenario says it**,
in `entitySettings`, and this extension adds nothing. What the engine writes
into a row is the suggested `uid`, the node pointer, the parent pointer and the
sibling `pid`. Everything else comes from `defaultValues` and from the declared
values, in that order.

Two consequences reach an integrator, and both are stated on
[Seed definitions](../development/seed-definitions.md#hidden-is-not-defaulted-for-you):

- `EXT:core/Configuration/TCA/Overrides/pages.php` sets
  `$GLOBALS['TCA']['pages']['columns']['hidden']['config']['default'] = 1`, so a
  scenario that does not carry `defaultValues: {hidden: 0}` seeds a **hidden**
  page tree. Every TYPO3 Core scenario carries it, which is why nobody notices
  when copying one.
- `doktype`, `l10n_parent` and `sys_language_uid` come from the TCA of the
  installation. The first two have a default there - the shipped `pages` TCA and
  `TcaEnrichment::enrichTransOrigPointerField()` - and the third does not, which
  DataHandler covers with `addDefaultPermittedLanguageIfNotSet()`.

The one place where relying on the TCA is not merely a matter of taste is
`Core\Hooks\CreateSiteConfiguration`. Its early return reads
`(int)$fieldValues['l10n_parent']` **before** it reaches
`|| $dataHandler->isImporting`, so the read happens for every seeded page even
though the hook does nothing afterwards. It is unguarded on v13.4 and `?? 0` on
v14.3. On v13.4, exactly one TCA override removing the enrichment default
therefore separates a seeded page from an `Undefined array key` warning - which
this test suite turns into a failure. A scenario that declares `l10n_parent: 0`
in its `'*'` defaults is immune; this extension no longer declares it on the
scenario's behalf.

## Suggested uids need both halves

A declared `id` is a *suggestion*, and DataHandler reads it from two places at
once:

- the `uid` column of the **data map row**, which is where `insertDB()` takes
  the suggestion from (it then drops the column again - *"Do NOT insert the UID
  field, ever!"* - so putting it there cannot write a uid by itself).
  `DataHandlerFactory::processEntityValues()` writes it;
- `DataHandler::$suggestedInsertUids`, keyed **`"<table>:<uid>"`** and not by the
  placeholder, because that is the key `insertDB()` looks up.
  `DataHandlerWriter::invokeFactory()` assigns it from `getSuggestedIds()`.

Getting either half wrong fails silently: DataHandler assigns the next free uid,
the run reports whatever it got, and the result is right only as long as
declaration order happens to equal insertion order.

That the writer assigns `suggestedInsertUids` **itself** is why the port gained
its one additive parameter. `--force` has to hold back the suggestions of a
colliding table, and there is no moment between "the writer was constructed" and
"the uid is read" in which a caller could reduce the array from the outside; so
`DataHandlerWriter::__construct()` takes the set to withhold. See
[A withheld set of suggested uids](scenario-engine.md#a-withheld-set-of-suggested-uids).

A suggestion is not a demand in the other direction either. Leaving it in for a
uid another row already holds does not write the record elsewhere; it makes the
`INSERT` fail on the primary key, because DataHandler forces the uid into the
field array. What that looks like was established by removing the collision
check and watching it: on v13.4 the failed insert returns `null`, the `pages`
branch of `process_datamap()` hands that `null` to
`addDefaultPermittedLanguageIfNotSet()` and a `TypeError` comes out - not even
the logged SQL error one would expect. Hence `UidCollisionDetector`, and hence
`--force` dropping suggestions instead of overriding the refusal; see
[Seed sets and the CLI](../development/seed-sets.md#uid-collisions-and---force).

### Reading back what was written

`ScenarioSeeder` needs to know which uid a declared `id` ended up under, because
a site configuration names its root page by that number and `--force` may have
taken the suggestion away.

`DataHandler::$substNEWwithIDs` is the only place that answer exists, which is
why the seeder constructs `DataHandlerWriter` with a `DataHandler` of its own
rather than through `DataHandlerWriter::withBackendUser()`: that named
constructor makes the instance and keeps it private. The second reason is
`isImporting`, which has to be set before the first `start()`. What the named
constructor adds on top - carrying `uc['copyLevels']` into
`DataHandler::$copyTree` - applies to a `copy` command, and the command map a
scenario produces knows `move`, `delete` and `clearWSID` only.

A record of a later workspace round whose key was already substituted has no
entry in `substNEWwithIDs`: it updated an existing row rather than inserting
one, and is passed over.

## Files are seeded, file references are not

`FileSeeder` runs before the records and is unchanged: it copies the files a set
declares into a storage through the FAL API - which is what indexes them, a file
copied into `fileadmin/` with `cp` exists on disk and does not exist for TYPO3 -
and returns the `sys_file` uid each one was indexed under.

**`sys_file_reference` rows are not written in this step, and that is a
deliberate gap rather than an oversight.** The scenario format has no concept of
a file. The structural reason is in the factory: it emits one flat data map
entry per item and has no way to write a child's `NEW` id back into a *field* of
the parent, which is exactly what a file reference needs. Inventing a key for it
would be inventing a divergence from a format whose whole value is that it is
not ours, so it is built on top instead - and building it is a step of its own.

The same applies to inline relations. In the scenario format a relation is
expressed by uid, because every record has a declared or a suggested one; the
convenience of declaring a child inline is what is missing, not the ability to
relate two records.

Both are named in the pull request rather than left to be discovered.

## `isImporting`, and what it costs

`ScenarioSeeder` sets `DataHandler::$isImporting` before the first `start()`. It
is the flag that suppresses the `autogenerated-<uid>` site configuration TYPO3
writes for every new page on the page tree root or carrying `is_siteroot` -
`Core\Hooks\CreateSiteConfiguration`, whose early return ends in
`|| $dataHandler->isImporting`. It is the same flag `EXT:impexp` sets for the
same reason, in six places, and the core has a functional test named
`importDoesNotCreateSiteConfigurationWhenDisabled()` for it. It is not
deprecated: searching the changelogs of 13.4 and 14.3 for `isImporting`,
`CreateSiteConfiguration` and `processDatamapClass` returns nothing.

It is a **public flag with nine consumers**, so adopting it blind is not
acceptable. Enumerated on both versions - the lists are identical - the
consumers outside `impexp` are:

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

The **beneficial** one keeps its value even though no file reference is written
in this step. Without the flag, a reference to a file in a folder the backend
user has no file mount for has its entire field array nulled and an error
logged - which is exactly the situation a seed run from the command line is in,
and exactly the situation the step that adds file references will be in.

The **caveat** is real and has to be stated in the user documentation: a seed
set writing a `be_users` record has to declare `username` and `password` itself,
and gets a user that cannot log in if it does not.

The flag is set **unconditionally**, not only for a definition that declares
`sites`. An `autogenerated-<uid>` site is never what a seed wanted, and a set
declaring no site is a set whose site configuration comes from elsewhere - not
an invitation to invent one. What that leaves behind is reported rather than
silently accepted; see
[Site configurations](site-configuration.md#the-uncovered-site-roots-report).

## Errors are not logged, they are raised

DataHandler reports a refused write by logging it and carrying on, so a run that
wrote half a page tree looks exactly like one that wrote all of it. The port
inherits that: `DataHandlerWriter::invokeFactory()` returns `void` and collects
`DataHandler::$errorLog` into `getErrors()`, and a caller that does not look
never learns.

`ScenarioSeeder` looks. A non-empty error list becomes a
`SeedingFailedException` naming the set and the distinct messages, which is what
makes the difference visible. An empty data map is refused for the same reason
before anything runs - a set that writes nothing is a set whose scenario was not
read the way it was meant.

The same reasoning applies one level up, in the parser:
`YamlFileLoader::processImports()` catches its exceptions per import and reports
them to a logger. For a site configuration that is a reasonable trade - the site
still loads, minus an optional include. For a seed descriptor it is data loss,
so `ThrowOnErrorLogger` raises the report back into an exception. Only error
level and above, so a future core version logging something informational from
the loader does not turn a working definition into a failure.

## The layout of `Classes/Seeding/`

| Directory       | What lives there                                                                                                                                              |
|-----------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `Definition/`   | The parsed **set descriptor** and its parts: `SeedDefinition`, `SeedFile`, `SeedSiteConfiguration`. Data, `#[Exclude]`, no dependencies.                      |
| `Parser/`       | `SeedDefinitionParser` and the `ThrowOnErrorLogger` it hands to `YamlFileLoader`.                                                                             |
| `Scenario/`     | The ported engine - `DataHandlerFactory`, `DataHandlerWriter`, `EntityConfiguration` - and `ScenarioComposer`, which is ours and composes the files of a set. |
| `DataHandling/` | Everything that writes: `ScenarioSeeder`, `FileSeeder`, `SiteConfigurationSeeder`, plus `UidCollisionDetector` and the two result objects.                    |
| `Exception/`    | `SeedingException` and its five subclasses, so a caller can tell "unknown set" from "invalid definition".                                                     |
| root            | `SeedSetRepository` and the `SeedSet` it returns - discovery, which is deliberately not parsing.                                                              |

Three properties of that layout are worth naming because they are easy to erode:

- **`Definition/` depends on nothing.** No service, no TYPO3 API, no other part
  of this extension - which is what would let the descriptor model be extracted
  into a package of its own later. It models the *set*, not its records, which
  is why `SeedRecord` and `SeedFileReference` went away with the record format
  and the other three stayed.
- **`Scenario/` is a boundary.** Three of its four classes are upstream's and
  are held to what upstream does by a conformance test; anything this extension
  invents belongs beside them, not inside them. `ScenarioComposer` is on the
  right side of that line: it composes and transforms *input*, and hands a
  finished settings array to an unmodified factory.
- **Discovery does not parse.** `SeedSetRepository` reads three keys with
  `Yaml::parseFile()` and stops. Parsing every set in full to show a title would
  make `seeder:list` as fragile as the least well maintained set in the
  installation; see
  [Seed sets and the CLI](../development/seed-sets.md#discovery-reads-metadata).

A field needs **no support anywhere in this pipeline to be seedable**. Every
declared value is copied into the data map untouched, through
`EntityConfiguration::processValues()` and nothing else. That is a design
decision made of the absence of a branch - a factory special-casing a field it
does not have to will special-case the next one too - and it is now upstream's
absence of a branch as well as ours.

## See also

- [The scenario engine](scenario-engine.md) - the port, its divergences and the conformance test
- [Seed definitions](../development/seed-definitions.md) - the two formats
- [Seed sets and the CLI](../development/seed-sets.md) - discovery, ordering, the commands
- [Site configurations](site-configuration.md) - what runs after the records
- [Class design](class-design.md) - why the definition objects carry `#[Exclude]`
- [Dependency injection](dependency-injection.md)
