<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown when an Entity match creation is asked to pair two entities of
 * different Works — a match pairs two distinct entities of the same Work.
 * Callers translate it into their own UX (the Library Alignments form keeps
 * its "Both entities must belong to the selected work." back-error).
 */
class CrossWorkEntityPair extends Exception {}
