<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown when a non-admin user tries to create an entity or alignment while
 * already holding their full share of concurrently processing work (ADR 0044).
 * Controllers catch it and turn it into a validation-style session error.
 */
class ProcessingLimitReached extends Exception {}
