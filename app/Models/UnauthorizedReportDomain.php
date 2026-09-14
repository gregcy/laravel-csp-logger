<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UnauthorizedReportDomain extends Model
{
    use HasFactory;

    protected $fillable = [
        'domain', 'occurrence_count', 'first_seen_at', 'last_seen_at', 'sample_raw_payload',
    ];

    protected $casts = [
        'sample_raw_payload' => 'array',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];
}
