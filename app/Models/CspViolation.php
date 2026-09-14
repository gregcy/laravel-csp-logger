<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CspViolation extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id', 'effective_directive', 'blocked_uri', 'source_file',
        'line_number', 'column_number', 'disposition', 'document_uri',
        'referrer', 'status_code', 'original_policy', 'script_sample',
        'raw_sample', 'occurrence_count', 'first_seen_at', 'last_seen_at',
    ];

    protected $casts = [
        'raw_sample' => 'array',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
