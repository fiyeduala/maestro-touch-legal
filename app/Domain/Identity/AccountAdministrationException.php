<?php

namespace App\Domain\Identity;

use RuntimeException;

/** A refused account change; the message is safe to show to the acting administrator. */
class AccountAdministrationException extends RuntimeException {}
