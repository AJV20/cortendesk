<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['rustdesk_id', 'uuid', 'typ', 'info', 'conn_id'])]
class AlarmLog extends Model {}
