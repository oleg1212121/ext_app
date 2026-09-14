<?php

namespace App\Classes;

use App\Models\Entity;
use App\Models\EntityMatch;

class EntitySentenceImportResult
{
    public function __construct(
        public EntityMatch $entityMatch,
        public Entity $aEntity,
        public Entity $bEntity,
        public int $pairCount,
    ) {}
}
