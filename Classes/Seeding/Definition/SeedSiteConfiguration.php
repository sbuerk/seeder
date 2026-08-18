<?php

declare(strict_types=1);

namespace SBUERK\Seeder\Seeding\Definition;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * One site configuration a seed definition sets up after its records are
 * written.
 *
 * A site cannot be written before the records are, because its `rootPageId` is
 * the uid of a page the same definition creates - which is why the definition
 * names the page by its *seed* identifier and the uid is resolved afterwards.
 *
 * This is data, not a service. Nothing in this class writes anything; the
 * writing side lives in the site configuration seeder.
 *
 * @internal Part of the seeding implementation, not public API.
 */
#[Exclude]
final readonly class SeedSiteConfiguration
{
    /**
     * @param string $identifier The site identifier, which becomes the
     *        directory name below the instance's `config/sites/`.
     * @param string $rootPage The **seed** identifier of the page record that
     *        becomes the site root. `rootPageId` is taken from its resolved uid
     *        and always wins over whatever the template declares, so a template
     *        cannot point the site somewhere else.
     * @param string $template Directory holding the `config.yaml` - and
     *        optionally `settings.yaml` - the site is written from, relative to
     *        {@see SeedDefinition::$basePath} or an `EXT:` path. The parser
     *        fills in `Sites/<identifier>` when the definition declares none,
     *        so this is never empty and no consumer has to know the default.
     * @param string|null $base Overrides the `base` of the template, for a set
     *        that is imported into more than one instance. Null leaves the
     *        template's value alone, which is not the same as an empty base.
     */
    public function __construct(
        public string $identifier,
        public string $rootPage,
        public string $template,
        public ?string $base = null,
    ) {}
}
