<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['rustdesk_id', 'from_peer', 'from_name', 'path', 'info', 'is_file', 'direction', 'file_count', 'ip', 'uuid'])]
class AuditFileTransfer extends Model
{
    protected function casts(): array
    {
        return [
            'is_file' => 'boolean',
        ];
    }
}
