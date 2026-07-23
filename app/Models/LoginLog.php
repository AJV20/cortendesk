<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'username', 'client', 'device_id', 'device_os', 'ip', 'successful', 'note'])]
class LoginLog extends Model
{
    protected function casts(): array
    {
        return [
            'successful' => 'boolean',
        ];
    }
}
