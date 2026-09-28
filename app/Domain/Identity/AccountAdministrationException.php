<?php

namespace App\Domain\Identity;

use App\Domain\RuleViolation;

/** A refused account change; the message is safe to show to the acting administrator. */
class AccountAdministrationException extends RuleViolation {}
