<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown when an Entity match creation is asked to pair an entity with
 * itself — a match pairs two distinct entities of the same Work. The entry
 * forms' different rule stops self-pairs first, so this is the module's
 * backstop; callers translate it into their own UX.
 */
class SelfEntityPair extends Exception {}
