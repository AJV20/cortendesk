<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['address_book_id', 'name', 'color'])]
class Tag extends Model
{
    public function addressBook(): BelongsTo
    {
        return $this->belongsTo(AddressBook::class);
    }
}
