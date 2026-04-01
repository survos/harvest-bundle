<?php
declare(strict_types=1);

namespace Survos\HarvestBundle\Attribute;

/**
 * Attach to a Harvest service/subscriber to describe the data source.
 *
 * Example:
 * #[Harvest(
 *   code: 'cleveland',
 *   name: 'Cleveland Museum of Art',
 *   version: '1.1',
 *   origin: 'github',
 *   links: ['github' => 'https://github.com/ClevelandMuseumArt/openaccess'],
 *   locale: 'en',
 *   description: "The Cleveland Museum of Art ...",
 *   dtos: ['object' => App\Pixie\Cleveland\ObjectDTO::class]
 * )]
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Harvest
{
    /**
     * @param array<string,string> $links
     * @param array<string,class-string> $dtos  Map entity => DTO class
     */
    public function __construct(
        public string $code,
        public string $name,
        public ?string $version = null,
        public ?string $origin = null,        // api|csv|excel|html|github|normalized_json
        public array $links = [],
        public ?string $locale = null,
        public ?string $description = null,
        public array $dtos = []
    ) {}
}
