<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Outgoing email log: metadata only, never message bodies. */
class Delivery extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];
}
