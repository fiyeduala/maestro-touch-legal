<?php

namespace App\Policies;

/** Append-only in the application: viewable by full administrators, never editable or deletable. */
class AuditEventPolicy extends AdministratorOnlyPolicy {}
