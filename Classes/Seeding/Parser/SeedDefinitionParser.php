<?php

declare(strict_types=1);

namespace SBUERK\Seeder\Seeding\Parser;

use SBUERK\Seeder\Seeding\Definition\SeedDefinition;
use SBUERK\Seeder\Seeding\Definition\SeedFile;
use SBUERK\Seeder\Seeding\Definition\SeedFileReference;
use SBUERK\Seeder\Seeding\Definition\SeedRecord;
use SBUERK\Seeder\Seeding\Definition\SeedSiteConfiguration;
use SBUERK\Seeder\Seeding\Exception\InvalidSeedDefinitionException;
use SBUERK\Seeder\Seeding\Exception\SeedDefinitionNotFoundException;
use TYPO3\CMS\Core\Configuration\Loader\Exception\YamlFileLoadingException;
use TYPO3\CMS\Core\Configuration\Loader\Exception\YamlParseException;
use TYPO3\CMS\Core\Configuration\Loader\YamlFileLoader;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Reads a seed definition from a YAML file, or from an already decoded array.
 *
 * The format keeps the structural keys to a minimum, so that everything which
 * is not structure is a field of the record:
 *
 *     identifier: demo
 *     title: 'Demo page tree'
 *     pages:
 *       - identifier: root
 *         uid: 1
 *         title: 'Demo'
 *         slug: '/'
 *         is_siteroot: 1
 *         content:
 *           - identifier: root-welcome
 *             CType: header
 *             header: 'Welcome'
 *         children:
 *           - identifier: about
 *             title: 'About'
 *             slug: '/about'
 *
 * `identifier`, `uid`, `children` and `content` are structure; every other key
 * is written to the record as-is. `children` nests pages, `content` nests
 * `tt_content` records below the page carrying them. **A field needs no support
 * in the seeder to be seedable**, and the tests keep that true.
 *
 * `records` nests records of *any* table onto the page carrying them, which is
 * what `content` does for `tt_content` alone. A record declares the table it
 * belongs to itself, exactly as an inline child does:
 *
 *     pages:
 *       - identifier: storage
 *         doktype: 254
 *         title: 'Storage'
 *         records:
 *           - identifier: category-news
 *             table: sys_category
 *             title: 'News'
 *
 * That is what makes a seed definition able to describe the data a plugin
 * reads, rather than only the pages and content elements around it.
 *
 * `inline` nests records into a *relation* rather than below a page, as a map
 * of the parent field carrying the relation to the records declared for it:
 *
 *     content:
 *       - identifier: links
 *         CType: example_linklist
 *         inline:
 *           tx_example_items:
 *             - identifier: links-docs
 *               table: tx_example_item
 *               label: 'Documentation'
 *
 * An inline child declares the `table` it belongs to itself. Inferring it from
 * `config.foreign_table` of the parent's field would make a seed definition
 * depend on the TCA being loaded, and would fail with a null dereference rather
 * than a message when it is not - so `table` is structural on an inline child,
 * exactly as `identifier` is. It is structural under `records` for the same
 * reason and a simpler one: there is no parent field to infer anything from.
 *
 * `uid` is optional. Where it is given it is passed to DataHandler as a
 * *suggested* uid, which makes a seed reproducible - a site configuration can
 * then reference a root page id that is known in advance instead of whatever
 * the database happened to assign.
 *
 * @internal Part of the seeding implementation, not public API.
 */
final readonly class SeedDefinitionParser
{
    private const IDENTIFIER = 'identifier';
    private const TITLE = 'title';
    private const DESCRIPTION = 'description';
    private const IMPORTS = 'imports';
    private const PAGES = 'pages';
    private const SITES = 'sites';
    private const CONTENT = 'content';
    private const CHILDREN = 'children';
    private const RECORDS = 'records';
    private const UID = 'uid';
    private const FILES = 'files';
    private const INLINE = 'inline';
    private const TABLE = 'table';
    private const SOURCE = 'source';
    private const ROOT_PAGE = 'rootPage';
    private const TEMPLATE = 'template';
    private const BASE = 'base';

    /**
     * The keys a definition may carry at the top level.
     *
     * Unlike a record level, this one is closed: an unknown key here is a typo,
     * not a field of anything. Accepting it silently is how `page:` instead of
     * `pages:` becomes an import that reports success and writes nothing.
     *
     * `imports` is listed although the parser never sees it in practice -
     * `YamlFileLoader` merges and removes it - so that {@see self::parse()} can
     * be called with a raw array carrying one, and rejects the *unknown* rather
     * than the *consumed*.
     */
    private const SET_KEYS = [
        self::IDENTIFIER,
        self::TITLE,
        self::DESCRIPTION,
        self::IMPORTS,
        self::FILES,
        self::PAGES,
        self::SITES,
    ];

    /**
     * Keys that describe the shape of the definition rather than a field of the
     * record they appear on.
     *
     * `table` is deliberately not in here: it is structural only on an inline
     * or `records` child, and `tt_content` and `pages` both have fields whose
     * name starts with `table`. A key that is structure in one place and a
     * field in another has to be decided where the context is known, which is
     * per level.
     *
     * `records` is the same case and is decided the same way. `tt_content` has
     * a **column** of that name - the one the "Insert records" element writes
     * `tt_content_<uid>` into - so the key can only be structure on a record of
     * the `pages` table, which is also the only place it means anything.
     */
    private const STRUCTURAL_KEYS = [
        self::IDENTIFIER,
        self::UID,
        self::CHILDREN,
        self::CONTENT,
        self::FILES,
        self::INLINE,
    ];

    /**
     * The keys a `sites` entry may carry - closed for the same reason as
     * {@see self::SET_KEYS}. A site is configuration, not a record, so nothing
     * here is written verbatim and an unknown key can only be a mistake.
     */
    private const SITE_KEYS = [
        self::IDENTIFIER,
        self::ROOT_PAGE,
        self::TEMPLATE,
        self::BASE,
    ];

    /**
     * An identifier ends up inside the `NEW…` placeholder of the record, and a
     * placeholder naming a relation target must not contain an underscore - see
     * the docblock of {@see SeedRecord::placeholder()} for what DataHandler does
     * with one. Restricting the identifier itself is what keeps that guarantee,
     * and doing it here means the definition is rejected with a message rather
     * than seeding an empty relation without a word.
     */
    private const IDENTIFIER_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9-]*$/';

    /**
     * A site identifier never reaches a DataHandler placeholder, so the
     * underscore rule of {@see self::IDENTIFIER_PATTERN} does not apply to it.
     * It does become the name of a directory below the instance's
     * `config/sites/`, which is what this pattern guards: no separator, no
     * `.` or `..`, nothing that has to be escaped anywhere.
     */
    private const SITE_IDENTIFIER_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]*$/';

    /**
     * Reads the entry file of a seed set, following its `imports`.
     *
     * `imports` is handled by `TYPO3\CMS\Core\Configuration\Loader\YamlFileLoader`,
     * the loader the core reads its own site configurations with. It resolves a
     * resource relative to the file declaring it, accepts `EXT:` paths, and
     * merges an imported list into the importing one instead of replacing it -
     * which is exactly what a set split over several files needs, and it means
     * this extension requires nothing beyond `typo3/cms-core`.
     *
     * Two deliberate deviations from how the core calls it:
     *
     * - **Placeholders are switched off** (`PROCESS_IMPORTS` without
     *   `PROCESS_PLACEHOLDERS`). A seed definition is content, not
     *   configuration: `%` occurs in pairs in perfectly ordinary titles and
     *   body texts, and a `%…%` fragment that happens to name a key of the
     *   definition would be substituted with that key's value. Seeded content
     *   has to arrive in the database as it was written, and environment
     *   variables have no business being interpolated into it.
     * - **A failing import raises** rather than being logged, through
     *   {@see ThrowOnErrorLogger}, which the loader's own error handling makes
     *   necessary.
     *
     * @throws SeedDefinitionNotFoundException
     * @throws InvalidSeedDefinitionException
     */
    public function parseFile(string $fileName): SeedDefinition
    {
        $absoluteFileName = GeneralUtility::getFileAbsFileName($fileName);
        if ($absoluteFileName === '' || !is_file($absoluteFileName)) {
            throw new SeedDefinitionNotFoundException(
                sprintf('The seed definition "%s" does not exist.', $fileName),
                1787072801,
            );
        }
        if (!is_readable($absoluteFileName)) {
            throw new SeedDefinitionNotFoundException(
                sprintf('The seed definition "%s" could not be read.', $fileName),
                1787072802,
            );
        }

        $loader = new YamlFileLoader(new ThrowOnErrorLogger($fileName));
        try {
            $content = $loader->load($absoluteFileName, YamlFileLoader::PROCESS_IMPORTS);
        } catch (YamlFileLoadingException|YamlParseException $exception) {
            throw new InvalidSeedDefinitionException(
                sprintf('The seed definition "%s" is not readable YAML: %s', $fileName, $exception->getMessage()),
                1787072803,
                $exception,
            );
        }

        return $this->parse($content, $fileName, dirname($absoluteFileName));
    }

    /**
     * @param mixed $definition The decoded YAML.
     * @param string $source Names the definition in every message. A file name
     *        where there is one.
     * @param string $basePath Absolute directory every relative resource path
     *        of the definition resolves against, see
     *        {@see SeedDefinition::$basePath}.
     * @throws InvalidSeedDefinitionException
     */
    public function parse(mixed $definition, string $source = 'seed definition', string $basePath = ''): SeedDefinition
    {
        if (!is_array($definition)) {
            throw new InvalidSeedDefinitionException(
                sprintf('The seed definition "%s" is not a map.', $source),
                1787072810,
            );
        }

        foreach (array_keys($definition) as $key) {
            if (!in_array($key, self::SET_KEYS, true)) {
                throw new InvalidSeedDefinitionException(
                    sprintf(
                        'The seed definition "%s" declares the unknown key "%s". Known keys are: %s.',
                        $source,
                        (string)$key,
                        implode(', ', self::SET_KEYS),
                    ),
                    1787072814,
                );
            }
        }

        $identifier = $definition[self::IDENTIFIER] ?? null;
        if (!is_string($identifier) || $identifier === '') {
            throw new InvalidSeedDefinitionException(
                sprintf('The seed definition "%s" has no "identifier".', $source),
                1787072811,
            );
        }

        $title = $definition[self::TITLE] ?? null;
        if (!is_string($title) || $title === '') {
            throw new InvalidSeedDefinitionException(
                sprintf('The seed definition "%s" has no "title".', $source),
                1787072812,
            );
        }

        // "description:" with nothing behind it decodes to null, which the null
        // coalescing operator treats exactly like an absent key.
        $description = $definition[self::DESCRIPTION] ?? '';
        if (!is_string($description)) {
            throw new InvalidSeedDefinitionException(
                sprintf('The "description" of the seed definition "%s" is not a string.', $source),
                1787072813,
            );
        }

        $pages = $definition[self::PAGES] ?? [];
        if (!$this->isList($pages)) {
            throw new InvalidSeedDefinitionException(
                sprintf('The "pages" of the seed definition "%s" are not a list.', $source),
                1787072815,
            );
        }

        $seen = [];
        $files = $this->parseFiles($definition[self::FILES] ?? [], $source);
        $records = $this->parseRecords($pages, self::PAGES, $source, $seen);
        $sites = $this->parseSites($definition[self::SITES] ?? [], $source, $seen);

        return new SeedDefinition(
            identifier: $identifier,
            title: $title,
            description: $description,
            basePath: rtrim($basePath, '/'),
            records: $records,
            files: $files,
            sites: $sites,
        );
    }

    /**
     * @return list<SeedFile>
     */
    private function parseFiles(mixed $files, string $source): array
    {
        if (!$this->isList($files)) {
            throw new InvalidSeedDefinitionException(
                sprintf('The "files" of the seed definition "%s" are not a list.', $source),
                1787072820,
            );
        }

        $parsed = [];
        $seen = [];
        foreach ($files as $file) {
            if (!is_array($file)) {
                throw new InvalidSeedDefinitionException(
                    sprintf('A file of the seed definition "%s" is not a map.', $source),
                    1787072821,
                );
            }
            $identifier = $file[self::IDENTIFIER] ?? null;
            if (!is_string($identifier) || $identifier === '') {
                throw new InvalidSeedDefinitionException(
                    sprintf('A file of the seed definition "%s" has no "identifier".', $source),
                    1787072822,
                );
            }
            if (isset($seen[$identifier])) {
                throw new InvalidSeedDefinitionException(
                    sprintf('The file identifier "%s" is used more than once in "%s".', $identifier, $source),
                    1787072823,
                );
            }
            $seen[$identifier] = true;

            $sourcePath = $file[self::SOURCE] ?? null;
            if (!is_string($sourcePath) || $sourcePath === '') {
                throw new InvalidSeedDefinitionException(
                    sprintf('The file "%s" in "%s" has no "source".', $identifier, $source),
                    1787072824,
                );
            }

            $folder = $file['folder'] ?? '/';
            $name = $file['name'] ?? null;
            $storage = $file['storage'] ?? null;

            $parsed[] = new SeedFile(
                $identifier,
                $sourcePath,
                is_string($folder) ? $folder : '/',
                is_string($name) ? $name : null,
                is_int($storage) ? $storage : null,
            );
        }

        return $parsed;
    }

    /**
     * The file references of one record, as a map of field name to the
     * references declared for it.
     *
     * A reference is either the bare identifier of a seeded file, or a map
     * naming that identifier alongside the fields of the `sys_file_reference`
     * record - the alternative text, title, description and link an editor
     * fills in on a file relation:
     *
     *     files:
     *       image:
     *         - placeholder
     *         - identifier: portrait
     *           alternative: 'A portrait placeholder'
     *           description: 'Shown as the caption'
     *
     * A field declared with an empty list creates no reference at all and the
     * field is left alone. Seeding writes; an empty declaration is not an
     * instruction to clear a relation.
     *
     * @return array<string, list<SeedFileReference>>
     */
    private function parseFileReferences(mixed $files, string $recordIdentifier, string $source): array
    {
        if ($files === [] || $files === null) {
            return [];
        }
        if (!is_array($files)) {
            throw new InvalidSeedDefinitionException(
                sprintf('The "files" of "%s" in "%s" are not a map of field to file identifiers.', $recordIdentifier, $source),
                1787072850,
            );
        }

        $references = [];
        foreach ($files as $field => $identifiers) {
            if (!is_string($field) || $field === '') {
                throw new InvalidSeedDefinitionException(
                    sprintf('A file field of "%s" in "%s" is not a field name.', $recordIdentifier, $source),
                    1787072851,
                );
            }
            if (!$this->isList($identifiers)) {
                throw new InvalidSeedDefinitionException(
                    sprintf('The file field "%s" of "%s" in "%s" is not a list.', $field, $recordIdentifier, $source),
                    1787072852,
                );
            }
            foreach ($identifiers as $reference) {
                $references[$field][] = $this->parseFileReference($reference, $recordIdentifier, $source);
            }
        }

        return $references;
    }

    private function parseFileReference(mixed $reference, string $recordIdentifier, string $source): SeedFileReference
    {
        if (!is_array($reference)) {
            if (!is_string($reference) || $reference === '') {
                throw new InvalidSeedDefinitionException(
                    sprintf('A file reference of "%s" in "%s" is not an identifier.', $recordIdentifier, $source),
                    1787072853,
                );
            }

            return new SeedFileReference($reference);
        }

        $identifier = $reference[self::IDENTIFIER] ?? null;
        if (!is_string($identifier) || $identifier === '') {
            throw new InvalidSeedDefinitionException(
                sprintf('A file reference of "%s" in "%s" has no "identifier".', $recordIdentifier, $source),
                1787072854,
            );
        }

        $values = [];
        foreach ($reference as $key => $value) {
            if ($key === self::IDENTIFIER) {
                continue;
            }
            if (!is_string($key)) {
                throw new InvalidSeedDefinitionException(
                    sprintf(
                        'A field name of the file reference "%s" of "%s" in "%s" is not a string.',
                        $identifier,
                        $recordIdentifier,
                        $source,
                    ),
                    1787072855,
                );
            }
            if ($value !== null && !is_scalar($value)) {
                throw new InvalidSeedDefinitionException(
                    sprintf(
                        'The field "%s" of the file reference "%s" of "%s" in "%s" is not a scalar value.',
                        $key,
                        $identifier,
                        $recordIdentifier,
                        $source,
                    ),
                    1787072856,
                );
            }
            $values[$key] = $value;
        }

        return new SeedFileReference($identifier, $values);
    }

    /**
     * @param array<mixed> $records
     * @param string|null $table The table these records belong to, or null when
     *        each of them declares its own - which is the case for inline
     *        children, where one field may even point at a different table than
     *        the next, and for the records of a page.
     * @param array<string, string> $seen Identifiers already used and the table
     *        they were used on, by reference, so a duplicate is caught across
     *        the whole definition rather than per level - and so a site can be
     *        told whether its root page exists.
     * @param string $childContext Names the structural key these records were
     *        declared under, for the messages of the levels that do not have a
     *        table to name themselves by.
     * @return list<SeedRecord>
     */
    private function parseRecords(array $records, ?string $table, string $source, array &$seen, string $childContext = self::INLINE): array
    {
        $context = $table ?? $childContext;
        $parsed = [];
        foreach ($records as $record) {
            if (!is_array($record)) {
                throw new InvalidSeedDefinitionException(
                    sprintf('A record of "%s" in the seed definition "%s" is not a map.', $context, $source),
                    1787072830,
                );
            }

            $identifier = $record[self::IDENTIFIER] ?? null;
            if (!is_string($identifier) || $identifier === '') {
                throw new InvalidSeedDefinitionException(
                    sprintf('A record of "%s" in the seed definition "%s" has no "identifier".', $context, $source),
                    1787072831,
                );
            }
            if (preg_match(self::IDENTIFIER_PATTERN, $identifier) !== 1) {
                throw new InvalidSeedDefinitionException(
                    sprintf(
                        'The identifier "%s" in the seed definition "%s" is not usable. An identifier may contain letters, digits and dashes only, and has to start with a letter or a digit: it becomes part of the "NEW…" placeholder DataHandler resolves relations by, and an underscore in that placeholder makes the relation resolve to nothing.',
                        $identifier,
                        $source,
                    ),
                    1787072832,
                );
            }
            if (isset($seen[$identifier])) {
                throw new InvalidSeedDefinitionException(
                    sprintf(
                        'The identifier "%s" is used more than once in the seed definition "%s". Identifiers have to be unique.',
                        $identifier,
                        $source,
                    ),
                    1787072833,
                );
            }

            $uid = $record[self::UID] ?? null;
            if ($uid !== null && (!is_int($uid) || $uid < 1)) {
                throw new InvalidSeedDefinitionException(
                    sprintf(
                        'The "uid" of "%s" in the seed definition "%s" has to be a positive integer.',
                        $identifier,
                        $source,
                    ),
                    1787072834,
                );
            }

            $recordTable = $table;
            if ($recordTable === null) {
                $declaredTable = $record[self::TABLE] ?? null;
                if (!is_string($declaredTable) || $declaredTable === '') {
                    throw new InvalidSeedDefinitionException(
                        sprintf(
                            'The "%s" child "%s" in the seed definition "%s" has no "table". It is never inferred: under "inline" it would have to come from the TCA of the parent field, and under "records" there is no field it could come from at all.',
                            $context,
                            $identifier,
                            $source,
                        ),
                        1787072835,
                    );
                }
                $recordTable = $declaredTable;
            }
            $seen[$identifier] = $recordTable;

            $children = [];
            $nestedContent = $record[self::CONTENT] ?? [];
            if ($nestedContent !== []) {
                if (!$this->isList($nestedContent)) {
                    throw new InvalidSeedDefinitionException(
                        sprintf('The "content" of "%s" in the seed definition "%s" is not a list.', $identifier, $source),
                        1787072836,
                    );
                }
                $children = [...$children, ...$this->parseRecords($nestedContent, 'tt_content', $source, $seen)];
            }
            // Only on a record of the "pages" table, where "records" cannot be
            // a field: see the docblock of STRUCTURAL_KEYS. The resolved table
            // decides, not the level, so a page declared below "records" is
            // still a page and may carry records of its own.
            $nestedRecords = $recordTable === self::PAGES ? ($record[self::RECORDS] ?? []) : [];
            if ($nestedRecords !== []) {
                if (!$this->isList($nestedRecords)) {
                    throw new InvalidSeedDefinitionException(
                        sprintf('The "records" of "%s" in the seed definition "%s" is not a list.', $identifier, $source),
                        1787072837,
                    );
                }
                // Parsed with no table of their own, so each declares one. They
                // join the children of this record like content does: the page
                // carrying them becomes their pid, and the data map factory
                // chains the declaration order per table, so records of three
                // tables on one page do not disturb each other's sorting.
                $children = [...$children, ...$this->parseRecords($nestedRecords, null, $source, $seen, self::RECORDS)];
            }
            $nestedChildren = $record[self::CHILDREN] ?? [];
            if ($nestedChildren !== []) {
                if (!$this->isList($nestedChildren)) {
                    throw new InvalidSeedDefinitionException(
                        sprintf('The "children" of "%s" in the seed definition "%s" is not a list.', $identifier, $source),
                        1787072838,
                    );
                }
                $children = [...$children, ...$this->parseRecords($nestedChildren, self::PAGES, $source, $seen)];
            }

            $inline = $this->parseInline($record[self::INLINE] ?? [], $identifier, $source, $seen);

            // "table" is a field everywhere but on an inline or "records"
            // child, where it is the structural key naming the table the record
            // belongs to; "records" is a field everywhere but on a page.
            $structuralKeys = self::STRUCTURAL_KEYS;
            if ($table === null) {
                $structuralKeys[] = self::TABLE;
            }
            if ($recordTable === self::PAGES) {
                $structuralKeys[] = self::RECORDS;
            }

            $values = [];
            foreach ($record as $key => $value) {
                if (in_array($key, $structuralKeys, true)) {
                    continue;
                }
                if (!is_string($key)) {
                    throw new InvalidSeedDefinitionException(
                        sprintf('A field name of "%s" in the seed definition "%s" is not a string.', $identifier, $source),
                        1787072839,
                    );
                }
                if ($value !== null && !is_scalar($value)) {
                    throw new InvalidSeedDefinitionException(
                        sprintf(
                            'The field "%s" of "%s" in the seed definition "%s" is not a scalar value.',
                            $key,
                            $identifier,
                            $source,
                        ),
                        1787072840,
                    );
                }
                $values[$key] = $value;
            }

            $parsed[] = new SeedRecord(
                $recordTable,
                $identifier,
                $values,
                $uid,
                $children,
                $this->parseFileReferences($record[self::FILES] ?? [], $identifier, $source),
                $inline,
            );
        }

        return $parsed;
    }

    /**
     * The inline children of one record, as a map of the parent field carrying
     * the relation to the records declared for it.
     *
     * @param array<string, string> $seen Identifiers already used, by reference,
     *        so an inline child cannot reuse an identifier either.
     * @return array<string, list<SeedRecord>>
     */
    private function parseInline(mixed $inline, string $recordIdentifier, string $source, array &$seen): array
    {
        if ($inline === [] || $inline === null) {
            return [];
        }
        if (!is_array($inline)) {
            throw new InvalidSeedDefinitionException(
                sprintf('The "inline" of "%s" in "%s" is not a map of field name to child records.', $recordIdentifier, $source),
                1787072860,
            );
        }

        $parsed = [];
        foreach ($inline as $field => $children) {
            if (!is_string($field) || $field === '') {
                throw new InvalidSeedDefinitionException(
                    sprintf('An inline field of "%s" in "%s" is not a field name.', $recordIdentifier, $source),
                    1787072861,
                );
            }
            if (!$this->isList($children)) {
                throw new InvalidSeedDefinitionException(
                    sprintf('The inline field "%s" of "%s" in "%s" is not a list of records.', $field, $recordIdentifier, $source),
                    1787072862,
                );
            }
            $parsed[$field] = $this->parseRecords($children, null, $source, $seen);
        }

        return $parsed;
    }

    /**
     * The site configurations a definition declares.
     *
     * They are parsed last, because `rootPage` names a page by its seed
     * identifier and that can only be checked once every record of the
     * definition has been seen. Catching it here is the difference between a
     * message naming the unknown page and a site written with a root page id of
     * zero much later in the run.
     *
     * @param array<string, string> $seen Every identifier of the definition and
     *        the table it belongs to.
     * @return list<SeedSiteConfiguration>
     */
    private function parseSites(mixed $sites, string $source, array $seen): array
    {
        if (!$this->isList($sites)) {
            throw new InvalidSeedDefinitionException(
                sprintf('The "sites" of the seed definition "%s" are not a list.', $source),
                1787072816,
            );
        }

        $parsed = [];
        $declared = [];
        foreach ($sites as $site) {
            if (!is_array($site)) {
                throw new InvalidSeedDefinitionException(
                    sprintf('A site of the seed definition "%s" is not a map.', $source),
                    1787072870,
                );
            }
            foreach (array_keys($site) as $key) {
                if (!in_array($key, self::SITE_KEYS, true)) {
                    throw new InvalidSeedDefinitionException(
                        sprintf(
                            'A site of the seed definition "%s" declares the unknown key "%s". Known keys are: %s.',
                            $source,
                            (string)$key,
                            implode(', ', self::SITE_KEYS),
                        ),
                        1787072878,
                    );
                }
            }

            $identifier = $site[self::IDENTIFIER] ?? null;
            if (!is_string($identifier) || $identifier === '') {
                throw new InvalidSeedDefinitionException(
                    sprintf('A site of the seed definition "%s" has no "identifier".', $source),
                    1787072871,
                );
            }
            if (preg_match(self::SITE_IDENTIFIER_PATTERN, $identifier) !== 1) {
                throw new InvalidSeedDefinitionException(
                    sprintf(
                        'The site identifier "%s" in the seed definition "%s" is not usable. It becomes a directory name below "config/sites/", so it may contain letters, digits, dashes and underscores only, and has to start with a letter or a digit.',
                        $identifier,
                        $source,
                    ),
                    1787072872,
                );
            }
            if (isset($declared[$identifier])) {
                throw new InvalidSeedDefinitionException(
                    sprintf('The site identifier "%s" is used more than once in "%s".', $identifier, $source),
                    1787072873,
                );
            }
            $declared[$identifier] = true;

            $rootPage = $site[self::ROOT_PAGE] ?? null;
            if (!is_string($rootPage) || $rootPage === '') {
                throw new InvalidSeedDefinitionException(
                    sprintf('The site "%s" in "%s" has no "rootPage".', $identifier, $source),
                    1787072874,
                );
            }
            if (!isset($seen[$rootPage])) {
                throw new InvalidSeedDefinitionException(
                    sprintf(
                        'The site "%s" in "%s" declares the root page "%s", which no record of this definition declares.',
                        $identifier,
                        $source,
                        $rootPage,
                    ),
                    1787072875,
                );
            }
            if ($seen[$rootPage] !== self::PAGES) {
                throw new InvalidSeedDefinitionException(
                    sprintf(
                        'The site "%s" in "%s" declares the root page "%s", which is a record of "%s" rather than a page.',
                        $identifier,
                        $source,
                        $rootPage,
                        $seen[$rootPage],
                    ),
                    1787072876,
                );
            }

            $template = $site[self::TEMPLATE] ?? null;
            if ($template !== null && (!is_string($template) || $template === '')) {
                throw new InvalidSeedDefinitionException(
                    sprintf('The "template" of the site "%s" in "%s" is not a path.', $identifier, $source),
                    1787072877,
                );
            }

            $base = $site[self::BASE] ?? null;
            if ($base !== null && !is_string($base)) {
                throw new InvalidSeedDefinitionException(
                    sprintf('The "base" of the site "%s" in "%s" is not a string.', $identifier, $source),
                    1787072879,
                );
            }

            $parsed[] = new SeedSiteConfiguration(
                $identifier,
                $rootPage,
                $template ?? 'Sites/' . $identifier,
                $base,
            );
        }

        return $parsed;
    }

    /**
     * @phpstan-assert-if-true list<mixed> $value
     */
    private function isList(mixed $value): bool
    {
        return is_array($value) && array_is_list($value);
    }
}
