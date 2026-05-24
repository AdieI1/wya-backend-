<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Event extends Model
{
    protected $fillable = [
        'creator_id',
        'event_type_id',
        'status_id',
        'event_code',
        'event_name',
        'event_location',
        'event_date',
        'capacity',
        'unlimited_capacity',
        'allow_late_checkin',
        'auto_mark_absent',
        'join_policy',
        'banner_image',
    ];

    protected $casts = [
        'event_date' => 'date',
        'unlimited_capacity' => 'boolean',
        'allow_late_checkin' => 'boolean',
        'auto_mark_absent' => 'boolean',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function eventType(): BelongsTo
    {
        return $this->belongsTo(EventType::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(EventStatus::class, 'status_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(EventSession::class);
    }

    public function cancellation(): HasOne
    {
        return $this->hasOne(CancelledEvent::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(EventParticipant::class);
    }

    public function participantUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'event_participants')
            ->withTimestamps()
            ->withPivot(['attendance_status_id', 'role']);
    }
}
