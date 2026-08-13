<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Audit trail for outbound Apprise attempts. Transport configuration is held
 * separately in encrypted settings and is deliberately absent from this table.
 */
#[Fillable(['event', 'subject', 'status', 'title', 'error'])]
class NotificationDelivery extends Model
{
    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SUPPRESSED = 'suppressed';
}
