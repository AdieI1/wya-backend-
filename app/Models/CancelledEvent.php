<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CancelledEvent extends Model
{
    protected $fillable = [
        'event_id',
        'cancelled_by',
        'cancellation_reason',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
