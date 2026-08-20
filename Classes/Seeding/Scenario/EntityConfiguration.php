<?php

declare(strict_types=1);

namespace SBUERK\Seeder\Seeding\Scenario;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Model describing an entity configuration used in a data scenario — the
 * `entitySettings` block of one entity: its table, its node and parent
 * columns, its column aliases, its language columns, its default values and
 * its value instructions.
 *
 * Ported from `typo3/testing-framework` 9.6.1, class
 * `TYPO3\TestingFramework\Core\Functional\Framework\DataHandling\Scenario\EntityConfiguration`,
 * for the reason given on `DataHandlerFactory`.
 *
 * This is data, not a service.
 *
 * @internal This is part of the seeding implementation of this extension and
 *           not public API.
 */
#[Exclude]
final class EntityConfiguration
{
    private bool $isNode = false;
    private ?string $tableName = null;
    private ?string $parentColumnName = null;
    private ?string $nodeColumnName = null;
    /**
     * @var array<string, string>
     */
    private array $columnNames = [];

    /**
     * @var list<string>
     */
    private array $languageColumnNames = [];

    /**
     * @var array<string, mixed>
     */
    private array $defaultValues = [];

    /**
     * @var array<string, array<array-key, array<string, mixed>>>
     */
    private array $valueInstructions = [];

    /**
     * @param array<string, mixed> $settings
     */
    public static function fromArray(string $name, array $settings): EntityConfiguration
    {
        $target = new self($name);
        if (isset($settings['isNode'])) {
            $target->isNode = (bool)$settings['isNode'];
        }
        if (!empty($settings['tableName'])) {
            $target->tableName = $settings['tableName'];
        }
        if (!empty($settings['parentColumnName'])) {
            $target->parentColumnName = $settings['parentColumnName'];
        }
        if (!empty($settings['nodeColumnName'])) {
            $target->nodeColumnName = $settings['nodeColumnName'];
        }
        if (!empty($settings['columnNames'])) {
            $target->columnNames = $settings['columnNames'];
        }
        if (!empty($settings['languageColumnNames'])) {
            $target->languageColumnNames = $settings['languageColumnNames'];
        }
        if (!empty($settings['defaultValues'])) {
            $target->defaultValues = $settings['defaultValues'];
        }
        if (!empty($settings['valueInstructions'])) {
            $target->assertValueInstructions($settings['valueInstructions']);
            $target->valueInstructions = $settings['valueInstructions'];
        }
        return $target;
    }

    public function __construct(
        private readonly string $name,
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function isNode(): bool
    {
        return $this->isNode;
    }

    public function getTableName(): string
    {
        return $this->tableName ?? $this->name;
    }

    public function getParentColumnName(): ?string
    {
        return $this->parentColumnName;
    }

    public function getNodeColumnName(): ?string
    {
        return $this->nodeColumnName;
    }

    public function resolveColumnName(string $name): string
    {
        return $this->columnNames[$name] ?? $name;
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function processValues(array $values): array
    {
        $processedValues = $this->defaultValues;
        foreach ($values as $name => $value) {
            $processedValues[$this->resolveColumnName($name)] = $value;
        }
        foreach ($values as $name => $value) {
            $processedValues = $this->assignValueInstructions(
                $processedValues,
                $name,
                $value
            );
        }
        return $processedValues;
    }

    /**
     * @param list<string> $ancestorIds
     * @return array<string, string>
     */
    public function processLanguageValues(array $ancestorIds): array
    {
        if (empty($ancestorIds)) {
            throw new \RuntimeException(
                'Language ancestor IDs is empty',
                1533744471
            );
        }
        $processedValues = [];
        if (empty($this->languageColumnNames)) {
            return $processedValues;
        }
        $lastAncestorIdsIndex = count($ancestorIds) - 1;
        $lastLanguageColumnNamesIndex = count($this->languageColumnNames) - 1;
        foreach ($this->languageColumnNames as $index => $columnName) {
            if ($index === $lastLanguageColumnNamesIndex || $index > $lastAncestorIdsIndex) {
                $ancestorId = $ancestorIds[$lastAncestorIdsIndex];
            } else {
                $ancestorId = $ancestorIds[$index];
            }
            $processedValues[$columnName] = $ancestorId;
        }
        return $processedValues;
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function assignValueInstructions(array $values, string $name, mixed $value): array
    {
        // Deliberate divergence from `typo3/testing-framework` 9.6.1, which
        // indexes with `$value` directly. PHP coerces a null offset to the
        // empty string, and deprecates doing so as of PHP 8.5 - which this
        // extension supports and whose test suite fails on a deprecation. The
        // coercion is spelled out instead, so the lookup keeps hitting exactly
        // the key it hit before while nothing is deprecated.
        $key = $value ?? '';
        if (empty($this->valueInstructions[$name][$key])) {
            return $values;
        }
        return array_merge($values, $this->valueInstructions[$name][$key]);
    }

    /**
     * @param array<string, mixed> $valueInstructions
     */
    private function assertValueInstructions(array $valueInstructions): void
    {
        foreach ($valueInstructions as $columnName => $valueInstruction) {
            if (empty($valueInstruction) || !is_array($valueInstruction)) {
                throw new \LogicException(
                    sprintf(
                        'Value instruction for column "%s" must be array',
                        $columnName
                    ),
                    1533734368
                );
            }
        }
    }
}
