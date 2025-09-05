<?php
declare(strict_types=1);

namespace Survos\HarvestBundle\Metadata;

/** Immutable metadata extracted from #[Harvest] attributes. */
final class HarvestMetadata
{
    /**
     * @param array<string,string> $links
     * @param array<string,class-string> $dtos
     */
    public function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly ?string $version,
        public readonly ?string $origin,
        public readonly array $links,
        public readonly ?string $locale,
        public readonly ?string $description,
        public readonly array $dtos,
        public readonly string $serviceId,   // service id carrying the attribute
        public readonly string $class        // FQCN of the service
    ) {}
}