<?php

declare(strict_types=1);

namespace SBUERK\DataFactory\Seeding\Definition;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * One site configuration a seed set writes after its records.
 *
 * A site cannot be written before the records are: its `rootPageId` is the uid
 * of a page the same set creates, and that page has to exist before a site
 * points at it.
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
     * @param int $rootPage The **uid** of the page that becomes the site root -
     *        the `id` a scenario entity declares for it. A scenario record has
     *        no symbolic name; the declared id is its stable handle, and it is
     *        the uid the record is written with. `rootPageId` is taken from it
     *        and always wins over whatever the template declares, so a template
     *        cannot point the site somewhere else.
     * @param string $template Directory holding the `config.yaml` - and
     *        optionally `settings.yaml` - the site is written from, relative to
     *        {@see SeedDefinition::$basePath} or an `EXT:` path. The parser
     *        fills in `Sites/<identifier>` when the set declares none, so this
     *        is never empty and no consumer has to know the default.
     * @param string|null $base Overrides the `base` of the template, for a set
     *        that is imported into more than one instance. Null leaves the
     *        template's value alone, which is not the same as an empty base.
     */
    public function __construct(
        public string $identifier,
        public int $rootPage,
        public string $template,
        public ?string $base = null,
    ) {}
}
