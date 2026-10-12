<?php

declare(strict_types=1);

namespace Survos\HarvestBundle\Sync;

use Survos\FolioBundle\Catalog\DatasetPublicationClient;

/** Application-replaceable selection; plans must not write state or download folios. */
interface HarvestSelection
{
    public function plan(array $checkpoint, DatasetPublicationClient $client): SyncPlan;
}
