<?php

declare(strict_types=1);

namespace SBUERK\Seeder\Tests\Unit\Seeding\Scenario;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SBUERK\Seeder\Seeding\Scenario\DataHandlerFactory;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * A `versionVariants` entry is the one place in the scenario format where a
 * record is *not* given a data map key of its own: it is written under the key
 * of the record it versions, into the data map of another workspace. That is
 * what makes DataHandler produce a workspace version instead of a second live
 * record, and it is also what makes the feature fragile — anything that sends
 * the entry into the wrong workspace bucket silently overwrites the live
 * record it was supposed to version.
 *
 * `typo3/testing-framework` ships no test for any of it. This file pins the
 * shared key, the per-workspace split, the two refusals that guard the entry
 * and their order, what a version variant of a language variant inherits (very
 * little), and the failure mode of remapping the `workspace` column.
 *
 * The data map keys are `uniqid('NEW', true)` values and therefore differ on
 * every run. Expectations are built from the actual key list instead, which is
 * ordered by insertion and hence stable.
 */
final class DataHandlerFactoryVersionVariantTest extends UnitTestCase
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
    public function aVersionVariantIsWrittenUnderTheIdentifierOfTheRecordItVersions(): void
    {
        $factory = new DataHandlerFactory([
            'entitySettings' => ['page' => ['tableName' => 'pages']],
            'entities' => [
                'page' => [
                    [
                        'self' => ['title' => 'EN: Welcome'],
                        'versionVariants' => [
                            ['version' => ['title' => 'EN: Welcome to ACME Inc', 'workspace' => 1]],
                        ],
                    ],
                ],
            ],
        ]);

        $liveIdentifiers = self::identifiersOf($factory, 0, 'pages');
        $versionIdentifiers = self::identifiersOf($factory, 1, 'pages');

        // The shared key is the whole mechanism: DataHandler is handed the same
        // `NEW…` placeholder twice, once for workspace 0 and once for the
        // workspace of the variant, and creates a version of the record it just
        // created rather than an unrelated second one.
        $this->assertCount(1, $liveIdentifiers);
        $this->assertSame($liveIdentifiers, $versionIdentifiers);
        $this->assertSame([0, 1], array_keys($factory->getDataMapPerWorkspace()));
        $this->assertSame(['pages'], $factory->getDataMapTableNames());
    }

    #[Test]
    public function aVersionVariantGetsASuggestedUidOfItsOwn(): void
    {
        $factory = new DataHandlerFactory([
            'entitySettings' => ['page' => ['tableName' => 'pages']],
            'entities' => [
                'page' => [
                    [
                        'self' => ['title' => 'EN'],
                        'versionVariants' => [
                            ['version' => ['title' => 'EN in workspace 1', 'workspace' => 1]],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertSame(['title' => 'EN', 'uid' => 10000], self::recordsOf($factory, 0, 'pages')[0]);
        // Sharing the data map key does not mean sharing the row: the version
        // is a record of its own in the database and is suggested an uid of its
        // own. The `workspace` value stays in the values as declared, it is not
        // consumed by the factory.
        $this->assertSame(
            ['title' => 'EN in workspace 1', 'workspace' => 1, 'uid' => 10001],
            self::recordsOf($factory, 1, 'pages')[0]
        );
        $this->assertSame(['pages:10000' => true, 'pages:10001' => true], $factory->getSuggestedIds());
    }

    /**
     * @return \Generator<string, array{versionVariant: array<string, mixed>, expectedCode: int, expectedMessage: string}>
     */
    public static function refusedVersionVariantDeclarations(): \Generator
    {
        yield 'self instead of version' => [
            'versionVariant' => ['self' => ['title' => 'EN']],
            'expectedCode' => 1574365935,
            'expectedMessage' => 'Cannot declare "self" in version variant for entity "page"',
        ];
        yield 'self next to version' => [
            'versionVariant' => ['self' => ['title' => 'EN'], 'version' => ['title' => 'EN', 'workspace' => 1]],
            'expectedCode' => 1574365935,
            'expectedMessage' => 'Cannot declare "self" in version variant for entity "page"',
        ];
        yield 'an id in the version' => [
            'versionVariant' => ['version' => ['id' => 1103, 'title' => 'EN', 'workspace' => 1]],
            'expectedCode' => 1574365936,
            'expectedMessage' => 'Cannot assign "id" for version variant for entity "page"',
        ];
        yield 'an id in a version without a workspace' => [
            // Both rules are broken; the id is the one reported, because it is
            // checked before the version declaration is validated at all.
            'versionVariant' => ['version' => ['id' => 1103, 'title' => 'EN']],
            'expectedCode' => 1574365936,
            'expectedMessage' => 'Cannot assign "id" for version variant for entity "page"',
        ];
    }

    /**
     * @param array<string, mixed> $versionVariant
     */
    #[DataProvider('refusedVersionVariantDeclarations')]
    #[Test]
    public function aMisdeclaredVersionVariantIsRefused(array $versionVariant, int $expectedCode, string $expectedMessage): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionCode($expectedCode);
        $this->expectExceptionMessage($expectedMessage);

        new DataHandlerFactory([
            'entitySettings' => ['page' => ['tableName' => 'pages']],
            'entities' => [
                'page' => [
                    [
                        'self' => ['title' => 'EN'],
                        'versionVariants' => [$versionVariant],
                    ],
                ],
            ],
        ]);
    }

    /**
     * The workspace a version variant belongs to is the one it declares, and it
     * is read from the declaration rather than from the processed values.
     *
     * `typo3/testing-framework` 9.6.1 reads `$values['workspace']`, that is
     * after `columnNames` was applied, so an entity that renames its
     * `workspace` column loses it: the expression warns about an undefined key,
     * evaluates to workspace 0, and the variant is written under the key of the
     * live record - overwriting the record it was declared to version, with an
     * empty error log. That is the fifth divergence listed on
     * {@see UpstreamConformanceTest}, which also pins upstream's half of it.
     *
     * The renamed column is still renamed in the values: `columnNames` decides
     * what is written, it does not decide where.
     */
    #[Test]
    public function aRemappedWorkspaceColumnDoesNotMoveTheVariantIntoTheLiveWorkspace(): void
    {
        $factory = new DataHandlerFactory([
            'entitySettings' => [
                'page' => ['tableName' => 'pages', 'columnNames' => ['workspace' => 't3ver_wsid']],
            ],
            'entities' => [
                'page' => [
                    [
                        'self' => ['title' => 'EN'],
                        'versionVariants' => [
                            ['version' => ['title' => 'EN in workspace 1', 'workspace' => 1]],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertSame([0, 1], array_keys($factory->getDataMapPerWorkspace()));
        $this->assertSame(
            [['title' => 'EN', 'uid' => 10000]],
            self::recordsOf($factory, 0, 'pages')
        );
        $this->assertSame(
            [['title' => 'EN in workspace 1', 't3ver_wsid' => 1, 'uid' => 10001]],
            self::recordsOf($factory, 1, 'pages')
        );
    }

    #[Test]
    public function aVersionVariantOfALanguageVariantIsWrittenUnderTheLanguageVariantIdentifier(): void
    {
        $factory = new DataHandlerFactory([
            'entitySettings' => [
                'fileMetadata' => ['tableName' => 'sys_file_metadata', 'languageColumnNames' => ['l10n_parent']],
            ],
            'entities' => [
                'fileMetadata' => [
                    [
                        'self' => ['title' => 'EN file title'],
                        'languageVariants' => [
                            [
                                'self' => ['title' => 'FR file title'],
                                'versionVariants' => [
                                    ['version' => ['title' => 'FR workspaced title', 'workspace' => 1]],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $liveIdentifiers = self::identifiersOf($factory, 0, 'sys_file_metadata');
        $versionIdentifiers = self::identifiersOf($factory, 1, 'sys_file_metadata');

        $this->assertCount(2, $liveIdentifiers);
        // The version is a version of the translation, not of the original —
        // a `versionVariants` block below a `languageVariants` block is
        // resolved against the record it is nested in.
        $this->assertSame([$liveIdentifiers[1]], $versionIdentifiers);
    }

    #[Test]
    public function aVersionVariantDoesNotInheritTheComputedLanguageValues(): void
    {
        $factory = new DataHandlerFactory([
            'entitySettings' => [
                'fileMetadata' => ['tableName' => 'sys_file_metadata', 'languageColumnNames' => ['l10n_parent']],
            ],
            'entities' => [
                'fileMetadata' => [
                    [
                        'self' => ['title' => 'EN file title'],
                        'languageVariants' => [
                            [
                                'self' => ['title' => 'FR file title'],
                                'versionVariants' => [
                                    ['version' => ['title' => 'FR workspaced title', 'workspace' => 1]],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $liveIdentifiers = self::identifiersOf($factory, 0, 'sys_file_metadata');
        // The live translation does carry the language pointer …
        $this->assertSame($liveIdentifiers[0], self::recordsOf($factory, 0, 'sys_file_metadata')[1]['l10n_parent']);

        // … and its version does not: only the values declared on the variant
        // itself are written. That is correct for DataHandler — a version
        // inherits every column it does not overwrite from its live record.
        $this->assertSame(
            ['title' => 'FR workspaced title', 'workspace' => 1, 'uid' => 10002],
            self::recordsOf($factory, 1, 'sys_file_metadata')[0]
        );
    }

    #[Test]
    public function aVersionVariantOfANodeRecordPointsItsNodeColumnAtTheVersionedRecord(): void
    {
        $factory = new DataHandlerFactory([
            'entitySettings' => [
                'page' => ['isNode' => true, 'tableName' => 'pages', 'nodeColumnName' => 'pid'],
            ],
            'entities' => [
                'page' => [
                    [
                        'self' => ['title' => 'EN'],
                        'versionVariants' => [
                            ['version' => ['title' => 'EN in workspace 1', 'workspace' => 1]],
                        ],
                    ],
                ],
            ],
        ]);

        $liveIdentifiers = self::identifiersOf($factory, 0, 'pages');

        // A node entity hands its own identifier down to its version variants,
        // undashed — unlike a language variant, which receives `-<identifier>`.
        // The version therefore declares the record it versions as its own
        // parent page, which DataHandler resolves to the live record's pid.
        $this->assertSame($liveIdentifiers[0], self::recordsOf($factory, 1, 'pages')[0]['pid']);
    }

    #[Test]
    public function versionVariantsOfSeveralWorkspacesProduceOneDataMapEach(): void
    {
        $factory = new DataHandlerFactory([
            'entitySettings' => ['page' => ['tableName' => 'pages']],
            'entities' => [
                'page' => [
                    [
                        'self' => ['title' => 'EN'],
                        'versionVariants' => [
                            ['version' => ['title' => 'draft', 'workspace' => 1]],
                            // A string workspace, as YAML delivers it when it is
                            // quoted; the map key is cast to int either way.
                            ['version' => ['title' => 'review', 'workspace' => '2']],
                        ],
                    ],
                ],
            ],
        ]);

        $liveIdentifiers = self::identifiersOf($factory, 0, 'pages');

        $this->assertSame([0, 1, 2], array_keys($factory->getDataMapPerWorkspace()));
        $this->assertSame($liveIdentifiers, self::identifiersOf($factory, 1, 'pages'));
        $this->assertSame($liveIdentifiers, self::identifiersOf($factory, 2, 'pages'));
        $this->assertSame('draft', self::recordsOf($factory, 1, 'pages')[0]['title']);
        $this->assertSame('review', self::recordsOf($factory, 2, 'pages')[0]['title']);
        // The declared value is not normalised, only the map key is.
        $this->assertSame('2', self::recordsOf($factory, 2, 'pages')[0]['workspace']);
        // One table, however many workspaces it appears in.
        $this->assertSame(['pages'], $factory->getDataMapTableNames());
    }
}
