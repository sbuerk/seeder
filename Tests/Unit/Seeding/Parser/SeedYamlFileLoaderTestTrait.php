<?php

declare(strict_types=1);

namespace SBUERK\Seeder\Tests\Unit\Seeding\Parser;

use SBUERK\Seeder\Seeding\Parser\SeedYamlFileLoaderInterface;
use TYPO3\CMS\Core\Information\Typo3Version;

/**
 * Hands a unit test the `SeedYamlFileLoaderInterface` implementation of the
 * running core version.
 *
 * A functional test fetches it from the container, which is where the wiring is
 * asserted. A unit test has no container, so the choice has to be made here -
 * and it is made in **test** code on purpose: the rule that a version difference
 * is split into `Core12/` and `Core13/` rather than decided by a conditional is
 * about the extension's own classes, and a test picking which of the two
 * implementations it exercises is not that. The alternative would be two copies
 * of {@see SeedDefinitionParserTest}, one per core version, differing in a
 * single `new`.
 *
 * The class name is **computed** rather than referenced, which is not cosmetic:
 * naming `\SBUERK\Seeder\Core13\Seeding\Parser\SeedYamlFileLoader` here would
 * make PHPStan reflect it while analysing the v12 leg, and its v13 only
 * constructor argument types would be reported as missing classes. Both
 * directories are on the composer autoload path on both versions, so the string
 * resolves either way, and `assertInstanceOf()` is what turns the `object` back
 * into a typed value.
 *
 * @todo Remove together with {@see SeedYamlFileLoaderInterface} when TYPO3 v12
 *       support is dropped, and construct the parser with nothing again.
 */
trait SeedYamlFileLoaderTestTrait
{
    private function seedYamlFileLoader(): SeedYamlFileLoaderInterface
    {
        $className = sprintf(
            'SBUERK\\Seeder\\Core%d\\Seeding\\Parser\\SeedYamlFileLoader',
            (new Typo3Version())->getMajorVersion(),
        );

        $this->assertTrue(
            class_exists($className),
            sprintf(
                'There is no YAML loader for the running core version: "%s" does not exist. Every supported '
                . 'version needs one below its "Core<major>/" directory.',
                $className,
            ),
        );

        $loader = new $className();
        $this->assertInstanceOf(SeedYamlFileLoaderInterface::class, $loader);

        return $loader;
    }
}
