<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['action', 'conn_id', 'rustdesk_id', 'from_peer', 'from_name', 'ip', 'session_id', 'conn_type', 'uuid', 'closed_at'])]
class AuditConnection extends Model
{
    protected function casts(): array
    {
        return [
            'closed_at' => 'datetime',
        ];
    }
}
