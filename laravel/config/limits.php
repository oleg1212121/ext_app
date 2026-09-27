<?php

// Per-user caps on concurrently processing work (ADR 0044): how many of a
// user's entities may sit mid-pipeline and how many of their alignments may
// be pending/aligning at once. Approved admins are always exempt.
return [
    'entities_processing_per_user' => (int) env('LIMIT_ENTITIES_PROCESSING_PER_USER', 2),

    'alignments_processing_per_user' => (int) env('LIMIT_ALIGNMENTS_PROCESSING_PER_USER', 1),
];
