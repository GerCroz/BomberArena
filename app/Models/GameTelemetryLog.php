<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GameTelemetryLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'room_code',
        'event_name',
        'source',
        'destination',
        'payload',
        'latency_ms',
        'created_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'latency_ms' => 'integer',
        'created_at' => 'datetime',
    ];
}
