<?php

namespace App\Policies;

/** Accounts are created only by invitation or registration, and are offboarded rather than deleted. */
class UserPolicy extends AdministratorOnlyPolicy {}
