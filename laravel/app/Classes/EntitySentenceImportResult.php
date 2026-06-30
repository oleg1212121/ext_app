<?php

namespace App\Classes;

use App\Models\EnEntity;
use App\Models\EnRuEntityMatch;
use App\Models\RuEntity;

class EntitySentenceImportResult
{
    public function __construct(
        public EnRuEntityMatch $entityMatch,
        public EnEntity $enEntity,
        public RuEntity $ruEntity,
        public int $pairCount,
    ) {}
}
