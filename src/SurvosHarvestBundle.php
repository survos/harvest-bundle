<?php

declare(strict_types=1);

namespace Survos\HarvestBundle;

use Survos\Kit\AbstractSurvosBundle;
use Survos\Kit\SurvosKitBundle;
use Symfony\Component\DependencyInjection\Kernel\RequiredBundle;

#[RequiredBundle(SurvosKitBundle::class)]
// Symfony\Component\HttpKernel\Bundle\Bundle <-- Flex auto-registration marker (see Survos\Kit\AbstractSurvosBundle)
final class SurvosHarvestBundle extends AbstractSurvosBundle
{
}
