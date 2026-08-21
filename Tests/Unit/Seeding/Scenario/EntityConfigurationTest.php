<?php

declare(strict_types=1);

namespace SBUERK\DataFactory\Tests\Unit\Seeding\Scenario;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SBUERK\DataFactory\Seeding\Scenario\EntityConfiguration;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * {@see EntityConfiguration} decides what a declared value ends up being
 * called and what else it drags in, and it does so with three mechanisms that
 * look alike from the outside and are not: `columnNames` renames a value,
 * `defaultValues` adds one that was not declared, and `valueInstructions`
 * expands one declared value into several columns.
 *
 * It was ported from `typo3/testing-framework` 9.6.1 without a single test, so
 * this class pins the mechanisms *and* their edges - the falsy guards that drop
 * a setting silently, the `(bool)` cast that makes `isNode: 'false'` a node,
 * and the value used as an array key, where PHP's key coercion decides which
 * instruction is found.
 *
 * What is deliberately not here: `processLanguageValues()` beyond the empty
 * cases reached through the settings guards. Its positional mapping of ancestor
 * ids is its own subject and is covered with the language variant traversal.
 */
final class EntityConfigurationTest extends UnitTestCase
{
    /**
     * @return \Generator<string, array{declared: mixed, expected: bool}>
     */
    public static function isNodeDeclarations(): \Generator
    {
        yield 'boolean true' => ['declared' => true, 'expected' => true];
        yield 'boolean false' => ['declared' => false, 'expected' => false];
        // The one that bites: YAML quotes make this a non-empty string, and
        // "(bool)'false'" is true. A scenario written with quotes gets the
        // opposite of what it says, and nothing complains.
        yield 'the string "false"' => ['declared' => 'false', 'expected' => true];
        yield 'the string "true"' => ['declared' => 'true', 'expected' => true];
        yield 'the string "0"' => ['declared' => '0', 'expected' => false];
        yield 'an empty string' => ['declared' => '', 'expected' => false];
        yield 'integer 1' => ['declared' => 1, 'expected' => true];
        yield 'integer 0' => ['declared' => 0, 'expected' => false];
        yield 'an empty array' => ['declared' => [], 'expected' => false];
        // "isset()" guards this one setting instead of "!empty()", so an
        // explicit NULL is indistinguishable from not declaring it at all.
        yield 'null' => ['declared' => null, 'expected' => false];
    }

    #[DataProvider('isNodeDeclarations')]
    #[Test]
    public function isNodeIsCastRatherThanInterpreted(mixed $declared, bool $expected): void
    {
        $subject = EntityConfiguration::fromArray('page', ['isNode' => $declared]);

        $this->assertSame($expected, $subject->isNode());
    }

    #[Test]
    public function anEntityIsNoNodeUnlessItSaysSo(): void
    {
        $this->assertFalse(EntityConfiguration::fromArray('page', [])->isNode());
        $this->assertFalse((new EntityConfiguration('page'))->isNode());
    }

    #[Test]
    public function theTableNameFallsBackToTheEntityName(): void
    {
        // This is what lets a scenario name an entity "pages" and declare
        // nothing else - and what makes a typo in an entity name a table name
        // that does not exist rather than an error.
        $this->assertSame('page', EntityConfiguration::fromArray('page', [])->getTableName());
        $this->assertSame('pages', EntityConfiguration::fromArray('page', ['tableName' => 'pages'])->getTableName());
    }

    /**
     * @return \Generator<string, array{settings: array<string, mixed>}>
     */
    public static function falsyScalarSettings(): \Generator
    {
        yield 'an empty string' => ['settings' => ['tableName' => '', 'parentColumnName' => '', 'nodeColumnName' => '']];
        yield 'integer zero' => ['settings' => ['tableName' => 0, 'parentColumnName' => 0, 'nodeColumnName' => 0]];
        yield 'null' => ['settings' => ['tableName' => null, 'parentColumnName' => null, 'nodeColumnName' => null]];
        yield 'false' => ['settings' => ['tableName' => false, 'parentColumnName' => false, 'nodeColumnName' => false]];
    }

    /**
     * Every setting except `isNode` is guarded by `!empty()`, individually. A
     * falsy declaration is therefore not applied and not reported either - the
     * configuration behaves as if the key had never been written. Worth pinning
     * because `nodeColumnName` and `parentColumnName` staying NULL is what
     * makes {@see \SBUERK\DataFactory\Seeding\Scenario\DataHandlerFactory} skip the
     * pointer assignment entirely.
     *
     * @param array<string, mixed> $settings
     */
    #[DataProvider('falsyScalarSettings')]
    #[Test]
    public function aFalsyScalarSettingIsDroppedSilently(array $settings): void
    {
        $subject = EntityConfiguration::fromArray('page', $settings);

        $this->assertSame('page', $subject->getTableName());
        $this->assertNull($subject->getParentColumnName());
        $this->assertNull($subject->getNodeColumnName());
    }

    #[Test]
    public function falsyArraySettingsAreDroppedSilently(): void
    {
        $subject = EntityConfiguration::fromArray('page', [
            'columnNames' => [],
            'defaultValues' => [],
            'languageColumnNames' => [],
        ]);

        $this->assertSame('title', $subject->resolveColumnName('title'));
        $this->assertSame(['title' => 'Root'], $subject->processValues(['title' => 'Root']));
        // The empty short circuit of processLanguageValues(), reached through
        // the settings guard rather than through a language variant.
        $this->assertSame([], $subject->processLanguageValues(['NEW1']));
    }

    /**
     * @return \Generator<string, array{declared: mixed}>
     */
    public static function invalidValueInstructions(): \Generator
    {
        yield 'a string' => ['declared' => 'textmedia'];
        yield 'an integer' => ['declared' => 1];
        yield 'a boolean' => ['declared' => true];
        yield 'null' => ['declared' => null];
        // An array, but an empty one: "empty()" rejects it before "is_array()"
        // is ever reached, so a column declared without a single instruction is
        // refused rather than ignored.
        yield 'an empty array' => ['declared' => []];
    }

    #[DataProvider('invalidValueInstructions')]
    #[Test]
    public function aValueInstructionThatIsNotAFilledArrayIsRefused(mixed $declared): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionCode(1533734368);
        $this->expectExceptionMessage('Value instruction for column "CType" must be array');

        EntityConfiguration::fromArray('content', ['valueInstructions' => ['CType' => $declared]]);
    }

    #[Test]
    public function emptyValueInstructionsAreNotValidatedAtAll(): void
    {
        // The validation sits behind the same "!empty()" guard as the
        // assignment, so "valueInstructions: []" is dropped before it is
        // checked. Nothing is wrong with that - it is pinned because it means
        // the guard, not the validation, decides whether the check runs.
        $subject = EntityConfiguration::fromArray('content', ['valueInstructions' => []]);

        $this->assertSame(['CType' => 'text'], $subject->processValues(['CType' => 'text']));
    }

    /**
     * Upstream declares `final public function __construct()` and builds with
     * `new static()`; the port is `final` and builds with `new self()`, so
     * `fromArray()` cannot hand back anything else. The name is `readonly` and
     * is the fallback table name, which is why it is set once, by the
     * constructor, and never again.
     */
    #[Test]
    public function theNameIsConstructorOnlyAndTheClassIsFinal(): void
    {
        $subject = EntityConfiguration::fromArray('page', ['tableName' => 'pages']);

        $this->assertSame('page', $subject->getName());
        $this->assertTrue((new \ReflectionClass(EntityConfiguration::class))->isFinal());
        $this->assertTrue((new \ReflectionProperty(EntityConfiguration::class, 'name'))->isReadOnly());
    }

    /**
     * @return \Generator<string, array{name: string, expected: string}>
     */
    public static function columnNameLookups(): \Generator
    {
        yield 'a declared alias is mapped' => ['name' => 'title', 'expected' => 'header'];
        yield 'an unknown name passes through' => ['name' => 'bodytext', 'expected' => 'bodytext'];
        // The mapping is one way: the target of an alias is not itself a key,
        // so writing the resolved name in a scenario keeps working.
        yield 'the target of an alias passes through' => ['name' => 'header', 'expected' => 'header'];
        yield 'the lookup is case sensitive' => ['name' => 'Title', 'expected' => 'Title'];
    }

    #[DataProvider('columnNameLookups')]
    #[Test]
    public function anUnmappedColumnNamePassesThrough(string $name, string $expected): void
    {
        $subject = EntityConfiguration::fromArray('content', ['columnNames' => ['title' => 'header']]);

        $this->assertSame($expected, $subject->resolveColumnName($name));
    }

    #[Test]
    public function processValuesStartsFromTheDefaultsAndThenAppliesTheDeclaredValues(): void
    {
        $subject = EntityConfiguration::fromArray('content', [
            'columnNames' => ['title' => 'header'],
            'defaultValues' => ['header' => 'default header', 'hidden' => 0],
        ]);

        // Order matters and is asserted: the defaults seed the array, so a
        // default that is overridden keeps the position it had, and a declared
        // value without a default is appended. Note that "defaultValues" are
        // keyed by the *resolved* column name while declared values are keyed
        // by the alias - the two never meet unless the aliases line up.
        $this->assertSame(
            ['header' => 'Root', 'hidden' => 0, 'bodytext' => 'Text'],
            $subject->processValues(['title' => 'Root', 'bodytext' => 'Text']),
        );
        $this->assertSame(
            ['header' => 'default header', 'hidden' => 1],
            $subject->processValues(['hidden' => 1]),
        );
    }

    #[Test]
    public function aValueInstructionExpandsOneDeclaredValueIntoSeveralColumns(): void
    {
        $subject = EntityConfiguration::fromArray('content', [
            'valueInstructions' => [
                'CType' => [
                    'textmedia' => ['CType' => 'textmedia', 'header_layout' => 2, 'imageorient' => 8],
                ],
            ],
        ]);

        // This is the mechanism the core scenarios use most: one readable value
        // in the YAML, several columns in the data map. The declared value
        // itself stays unless an instruction overwrites it.
        $this->assertSame(
            ['CType' => 'textmedia', 'header' => 'Root', 'header_layout' => 2, 'imageorient' => 8],
            $subject->processValues(['CType' => 'textmedia', 'header' => 'Root']),
        );
        // A value without a matching instruction is written as it stands.
        $this->assertSame(['CType' => 'text'], $subject->processValues(['CType' => 'text']));
    }

    #[Test]
    public function valueInstructionsAreKeyedByTheUnmappedNameAndWinOverTheMappedValue(): void
    {
        $subject = EntityConfiguration::fromArray('content', [
            'columnNames' => ['title' => 'header'],
            'valueInstructions' => [
                // Keyed by the name as written in the scenario, not by the
                // column it is mapped to …
                'title' => ['special' => ['header' => 'from the instruction', 'header_layout' => 2]],
                // … so an instruction keyed by the resolved column name is
                // never found, because the lookup happens before the mapping.
                'header' => ['special' => ['never_applied' => true]],
            ],
        ]);

        // And it is applied *after* the mapping, through array_merge(), so the
        // instruction overwrites the value the mapping just wrote.
        $this->assertSame(
            ['header' => 'from the instruction', 'header_layout' => 2],
            $subject->processValues(['title' => 'special']),
        );
    }

    /**
     * @return \Generator<string, array{value: mixed, expectedInstruction: string}>
     */
    public static function valuesUsedAsInstructionKeys(): \Generator
    {
        yield 'an empty string finds the empty key' => ['value' => '', 'expectedInstruction' => 'viaEmptyString'];
        // NULL is cast to '' as an array key, so a scenario that leaves a value
        // empty picks up the instruction written for the empty string.
        yield 'null finds the empty key' => ['value' => null, 'expectedInstruction' => 'viaEmptyString'];
        yield 'boolean true finds the key 1' => ['value' => true, 'expectedInstruction' => 'viaIntOne'];
        yield 'boolean false finds the key 0' => ['value' => false, 'expectedInstruction' => 'viaIntZero'];
        yield 'integer 1 finds the key 1' => ['value' => 1, 'expectedInstruction' => 'viaIntOne'];
        // A numeric string is a numeric key: '1' and 1 are the same lookup, so
        // an instruction cannot distinguish the two.
        yield 'the string "1" finds the key 1' => ['value' => '1', 'expectedInstruction' => 'viaIntOne'];
        yield 'the string "0" finds the key 0' => ['value' => '0', 'expectedInstruction' => 'viaIntZero'];
    }

    /**
     * The instruction lookup is `$this->valueInstructions[$name][$value]`, so
     * the declared value is subjected to PHP's array key coercion. YAML types
     * the value, PHP then flattens the types, and instructions written for
     * distinct values collapse onto each other.
     */
    #[DataProvider('valuesUsedAsInstructionKeys')]
    #[Test]
    public function theDeclaredValueIsCoercedIntoAnArrayKeyForTheInstructionLookup(
        mixed $value,
        string $expectedInstruction,
    ): void {
        $subject = EntityConfiguration::fromArray('content', [
            'valueInstructions' => [
                'flag' => [
                    '' => ['viaEmptyString' => true],
                    1 => ['viaIntOne' => true],
                    0 => ['viaIntZero' => true],
                ],
            ],
        ]);

        $this->assertSame(
            ['flag' => $value, $expectedInstruction => true],
            $subject->processValues(['flag' => $value]),
        );
    }

    #[Test]
    public function anArrayValueBreaksTheInstructionLookupForThatColumn(): void
    {
        $subject = EntityConfiguration::fromArray('content', [
            'valueInstructions' => ['flag' => ['on' => ['hidden' => 0]]],
        ]);

        // The message differs between PHP versions ("Illegal offset type in
        // isset or empty" up to 8.2, "Cannot access offset of type array …"
        // from 8.3), so only the type is asserted.
        $this->expectException(\TypeError::class);

        $values = $subject->processValues(['flag' => ['on', 'off']]);
        $this->fail(sprintf('Expected a TypeError, got %d value(s).', count($values)));
    }

    #[Test]
    public function anArrayValueIsHarmlessWhileNoInstructionExistsForItsColumn(): void
    {
        $subject = EntityConfiguration::fromArray('content', [
            'valueInstructions' => ['other' => ['on' => ['hidden' => 0]]],
        ]);

        // Only the column that *has* instructions is looked up, so the failure
        // above is not a general ban on array values - it appears the moment an
        // instruction is added for that column, which is the reason to pin both
        // halves.
        $this->assertSame(['flag' => ['on', 'off']], $subject->processValues(['flag' => ['on', 'off']]));
    }

    #[Test]
    public function anInstructionWithAnEmptyPayloadIsSkipped(): void
    {
        $subject = EntityConfiguration::fromArray('content', [
            'valueInstructions' => [
                'flag' => [
                    'declaredButEmpty' => [],
                    'used' => ['hidden' => 1],
                ],
            ],
        ]);

        // The validation only looks one level deep, so an empty payload is
        // accepted at build time and silently does nothing at use time.
        $this->assertSame(['flag' => 'declaredButEmpty'], $subject->processValues(['flag' => 'declaredButEmpty']));
        $this->assertSame(['flag' => 'used', 'hidden' => 1], $subject->processValues(['flag' => 'used']));
    }
}
