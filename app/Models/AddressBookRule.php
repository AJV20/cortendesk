<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['address_book_id', 'subject_type', 'subject_id', 'permission'])]
class AddressBookRule extends Model
{
    public const PERM_READ = 1;
    public const PERM_READ_WRITE = 2;
    public const PERM_FULL = 3;

    public function addressBook(): BelongsTo
    {
        return $this->belongsTo(AddressBook::class);
    }
}
