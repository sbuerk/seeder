<?php

declare(strict_types=1);

namespace SBUERK\DataFactory\Tests\Unit\Seeding\Scenario;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SBUERK\DataFactory\Seeding\Scenario\DataHandlerFactory;
use SBUERK\DataFactory\Seeding\Scenario\EntityConfiguration;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * `languageVariants` is the part of the scenario format that a reader is most
 * likely to get wrong, because the rule that fills the language columns is
 * positional and is nowhere written down: the ancestor chain is mapped onto
 * `languageColumnNames` by index, an index beyond the chain falls back to the
 * last ancestor, and the *last* column always takes the last ancestor whatever
 * its index is. With `['l10n_parent', 'l10n_source']` that produces the
 * intended result by coincidence of arity — `l10n_parent` ends up on the
 * original and `l10n_source` on the direct parent — and with any other number
 * of columns it produces something a reader would not predict.
 *
 * `typo3/testing-framework` ships no test for any of it, so this file pins the
 * rule over every combination of column count and chain depth, together with
 * the surrounding behaviour of a variant: which node pointer it inherits, which
 * one it does not, and that declared values win over the computed ones.
 *
 * The data map keys are `uniqid('NEW', true)` values and therefore differ on
 * every run. Expectations are built from the actual key list instead, which is
 * ordered by insertion and hence stable.
 */
final class DataHandlerFactoryLanguageVariantTest extends UnitTestCase
{
    /**
     * @return list<array<string, mixed>>
     */
    private static function recordsOf(DataHandlerFactory $factory, int $workspaceId, string $tableName): array
    {
        return array_values($factory->getDataMapPerWorkspace()[$workspaceId][$tableName] ?? []);
    }

    /**
     * @return list<string>
     */
    private static function identifiersOf(DataHandlerFactory $factory, int $workspaceId, string $tableName): array
    {
        return array_keys($factory->getDataMapPerWorkspace()[$workspaceId][$tableName] ?? []);
    }

    #[Test]
    public function aLanguageVariantIsWrittenAsAnOwnRecordCarryingTheAncestorIdentifier(): void
    {
        $factory = new DataHandlerFactory([
            'entitySettings' => [
                'page' => [
                    'tableName' => 'pages',
                    'languageColumnNames' => ['l10n_parent', 'l10n_source'],
                    'columnNames' => ['language' => 'sys_language_uid'],
                ],
            ],
            'entities' => [
                'page' => [
                    [
                        'self' => ['title' => 'EN: Welcome'],
                        'languageVariants' => [
                            ['self' => ['title' => 'FR: Bienvenue', 'language' => 1]],
                        ],
                    ],
                ],
            ],
        ]);

        $identifiers = self::identifiersOf($factory, 0, 'pages');
        $records = self::recordsOf($factory, 0, 'pages');

        $this->assertCount(2, $records);
        $this->assertSame(['title' => 'EN: Welcome', 'uid' => 10000], $records[0]);
        // The ancestor is referenced by its data map key, not by its uid: the
        // relation is resolved by DataHandler when the record is created.
        $this->assertSame(
            [
                'l10n_parent' => $identifiers[0],
                'l10n_source' => $identifiers[0],
                'title' => 'FR: Bienvenue',
                'sys_language_uid' => 1,
                'uid' => 10001,
            ],
            $records[1]
        );
    }

    #[Test]
    public function siblingLanguageVariantsAllPointAtTheOriginalRecord(): void
    {
        $factory = new DataHandlerFactory([
            'entitySettings' => [
                'page' => ['tableName' => 'pages', 'languageColumnNames' => ['l10n_parent', 'l10n_source']],
            ],
            'entities' => [
                'page' => [
                    [
                        'self' => ['title' => 'EN'],
                        'languageVariants' => [
                            ['self' => ['title' => 'FR']],
                            ['self' => ['title' => 'ES']],
                        ],
                    ],
                ],
            ],
        ]);

        $identifiers = self::identifiersOf($factory, 0, 'pages');
        $records = self::recordsOf($factory, 0, 'pages');

        $this->assertCount(3, $records);
        // Each variant of one level starts a chain of its own: the second
        // variant does not see the first one as an ancestor.
        foreach ([1, 2] as $index) {
            $this->assertSame($identifiers[0], $records[$index]['l10n_parent']);
            $this->assertSame($identifiers[0], $records[$index]['l10n_source']);
        }
    }

    /**
     * Every combination of `languageColumnNames` arity and ancestor chain
     * depth. The expectation is spelled out as indexes into the ancestor list
     * rather than recomputed from the production rule, so a change of the rule
     * shows up as a failure instead of being followed by the test.
     *
     * @return \Generator<string, array{columnCount: int, ancestorCount: int, expectedAncestorIndexes: list<int>}>
     */
    public static function positionalLanguageColumnMappings(): \Generator
    {
        yield 'one column, one ancestor' => [
            'columnCount' => 1,
            'ancestorCount' => 1,
            'expectedAncestorIndexes' => [0],
        ];
        yield 'one column takes the direct parent, not the original' => [
            'columnCount' => 1,
            'ancestorCount' => 2,
            'expectedAncestorIndexes' => [1],
        ];
        yield 'two columns and one ancestor collapse onto the same identifier' => [
            'columnCount' => 2,
            'ancestorCount' => 1,
            'expectedAncestorIndexes' => [0, 0],
        ];
        yield 'two columns and two ancestors map one to one' => [
            'columnCount' => 2,
            'ancestorCount' => 2,
            'expectedAncestorIndexes' => [0, 1],
        ];
        yield 'two columns and three ancestors skip the middle of the chain' => [
            'columnCount' => 2,
            'ancestorCount' => 3,
            'expectedAncestorIndexes' => [0, 2],
        ];
        yield 'three columns and one ancestor all collapse' => [
            'columnCount' => 3,
            'ancestorCount' => 1,
            'expectedAncestorIndexes' => [0, 0, 0],
        ];
        yield 'three columns and two ancestors repeat the last ancestor' => [
            'columnCount' => 3,
            'ancestorCount' => 2,
            'expectedAncestorIndexes' => [0, 1, 1],
        ];
        yield 'three columns and three ancestors map one to one' => [
            'columnCount' => 3,
            'ancestorCount' => 3,
            'expectedAncestorIndexes' => [0, 1, 2],
        ];
        yield 'three columns and four ancestors skip the third ancestor' => [
            'columnCount' => 3,
            'ancestorCount' => 4,
            'expectedAncestorIndexes' => [0, 1, 3],
        ];
        yield 'four columns and two ancestors repeat the last ancestor three times' => [
            'columnCount' => 4,
            'ancestorCount' => 2,
            'expectedAncestorIndexes' => [0, 1, 1, 1],
        ];
    }

    /**
     * @param list<int> $expectedAncestorIndexes
     */
    #[DataProvider('positionalLanguageColumnMappings')]
    #[Test]
    public function languageColumnsAreFilledPositionallyFromTheAncestorChain(
        int $columnCount,
        int $ancestorCount,
        array $expectedAncestorIndexes
    ): void {
        $columnNames = [];
        for ($index = 0; $index < $columnCount; $index++) {
            $columnNames[] = 'lang_' . $index;
        }
        // A chain of `$ancestorCount` nested language variants, so the innermost
        // one is handed exactly that many ancestor identifiers.
        $item = ['self' => ['title' => 'variant']];
        for ($level = $ancestorCount - 1; $level >= 0; $level--) {
            $item = ['self' => ['title' => 'level ' . $level], 'languageVariants' => [$item]];
        }
        $factory = new DataHandlerFactory([
            'entitySettings' => ['page' => ['tableName' => 'pages', 'languageColumnNames' => $columnNames]],
            'entities' => ['page' => [$item]],
        ]);

        $identifiers = self::identifiersOf($factory, 0, 'pages');
        $records = self::recordsOf($factory, 0, 'pages');
        $this->assertCount($ancestorCount + 1, $records);

        $expected = [];
        foreach ($expectedAncestorIndexes as $columnIndex => $ancestorIndex) {
            $expected['lang_' . $columnIndex] = $identifiers[$ancestorIndex];
        }
        $innermost = $records[$ancestorCount];
        $actual = array_filter(
            $innermost,
            static fn(string $columnName): bool => str_starts_with($columnName, 'lang_'),
            ARRAY_FILTER_USE_KEY
        );
        $this->assertSame($expected, $actual);
    }

    #[Test]
    public function aNestedLanguageVariantSourcesItsDirectParentAndParentsTheOriginal(): void
    {
        $factory = new DataHandlerFactory([
            'entitySettings' => [
                'page' => ['tableName' => 'pages', 'languageColumnNames' => ['l10n_parent', 'l10n_source']],
            ],
            'entities' => [
                'page' => [
                    [
                        'self' => ['title' => 'EN'],
                        'languageVariants' => [
                            [
                                'self' => ['title' => 'FR'],
                                'languageVariants' => [
                                    ['self' => ['title' => 'FR-CA']],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $identifiers = self::identifiersOf($factory, 0, 'pages');
        $records = self::recordsOf($factory, 0, 'pages');

        $this->assertCount(3, $records);
        $this->assertSame($identifiers[0], $records[1]['l10n_parent']);
        $this->assertSame($identifiers[0], $records[1]['l10n_source']);
        // The pairing the core scenarios rely on: the translation origin stays
        // the original record, while the translation source is the record the
        // variant was nested in.
        $this->assertSame($identifiers[0], $records[2]['l10n_parent']);
        $this->assertSame($identifiers[1], $records[2]['l10n_source']);
    }

    #[Test]
    public function declaredValuesOverrideTheComputedLanguageValues(): void
    {
        $factory = new DataHandlerFactory([
            'entitySettings' => [
                'page' => [
                    'tableName' => 'pages',
                    'languageColumnNames' => ['l10n_parent', 'l10n_source'],
                    'columnNames' => ['source' => 'l10n_source'],
                ],
            ],
            'entities' => [
                'page' => [
                    [
                        'self' => ['title' => 'EN'],
                        'languageVariants' => [
                            // Once as the column itself, once through a column
                            // alias — the merge happens after the aliases are
                            // resolved, so both win.
                            ['self' => ['title' => 'FR', 'l10n_parent' => 987, 'source' => 654]],
                        ],
                    ],
                ],
            ],
        ]);

        $records = self::recordsOf($factory, 0, 'pages');

        $this->assertSame(
            ['l10n_parent' => 987, 'l10n_source' => 654, 'title' => 'FR', 'uid' => 10001],
            $records[1]
        );
    }

    /**
     * Not reachable through the factory — every call site passes at least the
     * ancestor the variant is declared under — but the guard is the only thing
     * standing between an empty chain and an undefined index, so it is pinned
     * where it can be reached.
     */
    #[Test]
    public function processLanguageValuesRefusesAnEmptyAncestorChain(): void
    {
        $configuration = EntityConfiguration::fromArray('page', [
            'tableName' => 'pages',
            'languageColumnNames' => ['l10n_parent'],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1533744471);

        $configuration->processLanguageValues([]);
    }

    #[Test]
    public function anEntityWithoutLanguageColumnNamesGetsNoLanguageValues(): void
    {
        $factory = new DataHandlerFactory([
            'entitySettings' => ['content' => ['tableName' => 'tt_content']],
            'entities' => [
                'content' => [
                    [
                        'self' => ['header' => 'EN'],
                        'languageVariants' => [
                            ['self' => ['header' => 'FR']],
                        ],
                    ],
                ],
            ],
        ]);

        $records = self::recordsOf($factory, 0, 'tt_content');

        // The variant is still a record of its own, it simply carries no
        // pointer back to the record it was declared under.
        $this->assertSame(['header' => 'EN', 'uid' => 10000], $records[0]);
        $this->assertSame(['header' => 'FR', 'uid' => 10001], $records[1]);
    }

    #[Test]
    public function theNodeIdentifierOfALanguageVariantOfANodeEntityIsPrefixedWithADash(): void
    {
        $factory = new DataHandlerFactory([
            'entitySettings' => [
                'page' => [
                    'isNode' => true,
                    'tableName' => 'pages',
                    'nodeColumnName' => 'pid',
                    'languageColumnNames' => ['l10n_parent'],
                ],
            ],
            'entities' => [
                'page' => [
                    [
                        'self' => ['title' => 'EN'],
                        'languageVariants' => [
                            ['self' => ['title' => 'FR']],
                            ['self' => ['title' => 'ES']],
                        ],
                    ],
                ],
            ],
        ]);

        $identifiers = self::identifiersOf($factory, 0, 'pages');
        $records = self::recordsOf($factory, 0, 'pages');

        // A page translation is not a page *below* its original, so the node
        // pointer is negated: `-<identifier>` is DataHandler's "place next to
        // that record" rather than "place inside it".
        $this->assertArrayNotHasKey('pid', $records[0]);
        $this->assertSame('-' . $identifiers[0], $records[1]['pid']);
        $this->assertSame('-' . $identifiers[0], $records[2]['pid']);
    }

    #[Test]
    public function aLanguageVariantOfANonNodeEntityKeepsTheSurroundingNodeIdentifier(): void
    {
        $factory = new DataHandlerFactory([
            'entitySettings' => [
                'page' => ['isNode' => true, 'tableName' => 'pages'],
                // `page_uid` rather than `pid` on purpose: `pid` is rewritten a
                // second time by the sequential insert handling of the data map,
                // which would hide which node identifier was handed down here.
                'content' => ['tableName' => 'tt_content', 'nodeColumnName' => 'page_uid', 'languageColumnNames' => ['l18n_parent']],
            ],
            'entities' => [
                'page' => [
                    [
                        'self' => ['title' => 'root'],
                        'entities' => [
                            'content' => [
                                [
                                    'self' => ['header' => 'EN'],
                                    'languageVariants' => [
                                        ['self' => ['header' => 'FR']],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $pageIdentifiers = self::identifiersOf($factory, 0, 'pages');
        $records = self::recordsOf($factory, 0, 'tt_content');

        // Only a node entity negates the identifier it hands to its variants.
        // A content element translation stays on the very same page as its
        // original, so it inherits the surrounding node identifier verbatim.
        $this->assertSame($pageIdentifiers[0], $records[0]['page_uid']);
        $this->assertSame($pageIdentifiers[0], $records[1]['page_uid']);
    }

    #[Test]
    public function aLanguageVariantOfAChildRecordDoesNotInheritTheParentPointer(): void
    {
        $factory = new DataHandlerFactory([
            'entitySettings' => [
                'page' => [
                    'isNode' => true,
                    'tableName' => 'pages',
                    'nodeColumnName' => 'pid',
                    'parentColumnName' => 'parent_uid',
                    'languageColumnNames' => ['l10n_parent'],
                ],
            ],
            'entities' => [
                'page' => [
                    [
                        'self' => ['title' => 'root'],
                        'children' => [
                            [
                                'self' => ['title' => 'child'],
                                'languageVariants' => [
                                    ['self' => ['title' => 'child FR']],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $identifiers = self::identifiersOf($factory, 0, 'pages');
        $records = self::recordsOf($factory, 0, 'pages');

        $this->assertCount(3, $records);
        $this->assertSame($identifiers[0], $records[1]['parent_uid']);
        // A language variant is processed without a parent identifier, so the
        // parent column is not written at all — the variant is tied to its
        // ancestor through the language columns and through the node pointer of
        // the record it translates, and through nothing else.
        $this->assertArrayNotHasKey('parent_uid', $records[2]);
        $this->assertSame($identifiers[1], $records[2]['l10n_parent']);
        $this->assertSame('-' . $identifiers[1], $records[2]['pid']);
    }
}
