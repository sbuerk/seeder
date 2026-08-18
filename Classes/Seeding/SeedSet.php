<?php

declare(strict_types=1);

namespace SBUERK\Seeder\Seeding;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * A seed set as it was found on disk: what it is called, which package brings
 * it, and where it lives.
 *
 * This is what discovery returns, and it is deliberately *not* a parsed
 * definition. It carries the three metadata keys `seeder:list` shows and the
 * two paths every later step needs - and nothing that would require reading
 * the page tree, the files or the site configurations of the set. See
 * {@see SeedSetRepository} for why that separation exists.
 *
 * This is data, not a service.
 *
 * @internal Part of the seeding implementation, not public API.
 */
#[Exclude]
final readonly class SeedSet
{
    /**
     * @param string $identifier Declared in `config.yml`, globally unique
     *        across all active packages, and never derived from the directory
     *        holding the set - a derived identifier turns a collision between
     *        two packages into a silent one.
     * @param string $title Shown by `seeder:list`.
     * @param string $description Optional long text, empty when the set
     *        declares none.
     * @param string $packageName The composer name of the providing package,
     *        for example `vendor/demo-content`. Empty when the package has no
     *        composer manifest to read it from, which the extension key never
     *        is.
     * @param string $extensionKey The extension key of the providing package.
     *        It is what `seeder:list` names as the provider, because it is what
     *        an integrator sees in the extension list.
     * @param string $basePath Absolute path of the directory holding the set,
     *        without a trailing slash. Every relative resource path of the set
     *        resolves against it.
     * @param string $configFile Absolute path of the `config.yml` of the set,
     *        which is the entry point a full parse is handed.
     */
    public function __construct(
        public string $identifier,
        public string $title,
        public string $description,
        public string $packageName,
        public string $extensionKey,
        public string $basePath,
        public string $configFile,
    ) {}
}
