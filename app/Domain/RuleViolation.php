<?php

namespace App\Domain;

use RuntimeException;

/** A workflow rule refused the action. The message is written for the acting user and is safe to show. */
class RuleViolation extends RuntimeException {}
