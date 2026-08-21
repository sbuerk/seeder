<?php

declare(strict_types=1);

namespace SBUERK\Seeder\Tests\Unit\Seeding\Scenario;

use PHPUnit\Framework\Attributes\Test;
use SBUERK\Seeder\Seeding\Scenario\DataHandlerFactory;
use SBUERK\Seeder\Seeding\Scenario\DataHandlerWriter;
use SBUERK\Seeder\Tests\Unit\Seeding\Scenario\Fixtures\RecordingDataHandler;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * `DataHandlerWriter` runs one `DataHandler` pass per workspace and, before
 * each of them, replaces the `NEW…` identifiers a previous pass has already
 * written with the uids it produced. Two forms occur: `NEW…`, which points at a
 * record, and `-NEW…`, which points *behind* one - a `pid` of `-42` means "on
 * the page record 42 is on, sorted after it", a `move` command of `-42` means
 * "move behind record 42".
 *
 * `typo3/testing-framework` 9.6.1 strips the minus to look the identifier up
 * and returns the uid without putting it back, so `-NEW…` becomes `42`: a page
 * pointer instead of a position, or a move onto a page instead of behind a
 * record. This is the third divergence listed on {@see DataHandlerWriter} and
 * both halves of it are covered below.
 *
 * These are unit tests because the substitution is string handling. What they
 * need from `DataHandler` is a place to read `substNEWwithIDs` from and
 * something to hand the result to, which {@see RecordingDataHandler} is.
 */
final class DataHandlerWriterSubstitutionTest extends UnitTestCase
{
    private function writerFor(RecordingDataHandler $dataHandler): DataHandlerWriter
    {
        return new DataHandlerWriter($dataHandler, new BackendUserAuthentication());
    }

    /**
     * The data map half.
     *
     * Reaching it takes a record that is written in a later workspace round and
     * chained behind one that an earlier round already wrote, which is what the
     * version variant below arranges: it puts the identifier of the live record
     * into the workspace 1 map as well, so the record declared after it is
     * chained behind an identifier that is no longer a `NEW…` by the time
     * workspace 1 is written.
     */
    #[Test]
    public function aPidPointingBehindARecordOfAnEarlierRoundKeepsItsMinus(): void
    {
        $factory = new DataHandlerFactory([
            'entitySettings' => [
                'page' => ['isNode' => true, 'tableName' => 'pages', 'defaultValues' => ['pid' => 0]],
                'content' => ['tableName' => 'tt_content', 'nodeColumnName' => 'pid'],
            ],
            'entities' => [
                'page' => [
                    [
                        'self' => ['id' => 1000, 'title' => 'Root'],
                        'entities' => [
                            'content' => [
                                [
                                    'self' => ['id' => 300, 'title' => 'Live'],
                                    'versionVariants' => [
                                        ['version' => ['workspace' => 1, 'title' => 'Versioned']],
                                    ],
                                ],
                                ['version' => ['workspace' => 1, 'title' => 'Only in workspace 1']],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        $dataHandler = new RecordingDataHandler();

        $this->writerFor($dataHandler)->invokeFactory($factory);

        $this->assertCount(2, $dataHandler->recordedDataMaps);
        $workspaceRound = $dataHandler->recordedDataMaps[1]['tt_content'];
        $records = array_values($workspaceRound);

        // The version variant is written under the uid of the live record, so
        // it is the numeric key; the record chained behind it is the one still
        // keyed by an identifier.
        $this->assertSame([2, 'NEW'], [
            array_keys($workspaceRound)[0],
            substr((string)array_keys($workspaceRound)[1], 0, 3),
        ]);
        $this->assertSame('-2', $records[1]['pid']);
    }

    /**
     * The command map half, which is the reachable one: a language variant of a
     * node inherits `-<identifier of its original>` as its node pointer, so
     * `move`/`toTop` on a page translation is a command value of `-NEW…`. The
     * command map rounds run after every data map round, so the identifier is
     * always substituted by then.
     */
    #[Test]
    public function aMoveCommandPointingBehindARecordKeepsItsMinus(): void
    {
        $factory = new DataHandlerFactory([
            'entitySettings' => [
                'page' => [
                    'isNode' => true,
                    'tableName' => 'pages',
                    'languageColumnNames' => ['l10n_parent', 'l10n_source'],
                    'defaultValues' => ['pid' => 0],
                ],
            ],
            'entities' => [
                'page' => [
                    [
                        'self' => ['id' => 1000, 'title' => 'Root'],
                        'languageVariants' => [
                            [
                                'self' => ['id' => 1001, 'title' => 'Root FR', 'language' => 1],
                                'actions' => [['action' => 'move', 'type' => 'toTop']],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        $dataHandler = new RecordingDataHandler();

        $this->writerFor($dataHandler)->invokeFactory($factory);

        $this->assertCount(1, $dataHandler->recordedCommandMaps);
        $commands = array_values($dataHandler->recordedCommandMaps[0]['pages']);
        // Not "1": that would move the translation onto the page its original
        // is on, which for a root page is page 0.
        $this->assertSame(['move' => '-1'], $commands[0]);
    }

    /**
     * The counterpart, so the two forms are not confused: a plain `NEW…` is
     * substituted without a sign, and a value that merely looks like an
     * identifier is left alone.
     */
    #[Test]
    public function aPlainIdentifierIsSubstitutedWithoutASign(): void
    {
        $factory = new DataHandlerFactory([
            'entitySettings' => [
                'page' => ['isNode' => true, 'tableName' => 'pages', 'defaultValues' => ['pid' => 0]],
                'content' => ['tableName' => 'tt_content', 'nodeColumnName' => 'pid', 'columnNames' => ['title' => 'header']],
            ],
            'entities' => [
                'page' => [
                    [
                        'self' => ['id' => 1000, 'title' => 'Root'],
                        'entities' => [
                            'content' => [
                                ['version' => ['workspace' => 1, 'title' => 'NEWSLETTER']],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        $dataHandler = new RecordingDataHandler();

        $this->writerFor($dataHandler)->invokeFactory($factory);

        $record = array_values($dataHandler->recordedDataMaps[1]['tt_content'])[0];
        // The page was written in the round before, so its identifier is a uid
        // by now - and the record sits *on* that page rather than behind it.
        $this->assertSame(1, $record['pid']);
        $this->assertSame('NEWSLETTER', $record['header']);
    }
}
