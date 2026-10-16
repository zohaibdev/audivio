<?php

namespace App\Models;

/**
 * Thrown when something tries to modify or delete a written audit
 * snapshot. A RuntimeException rather than a validation error on purpose:
 * reaching this is a programming mistake, not bad user input.
 */
class AuditSnapshotIsImmutable extends \RuntimeException {}
