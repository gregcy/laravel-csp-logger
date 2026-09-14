<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Site extends Model
{
    use HasFactory;

    protected $fillable = ['domain', 'name', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (Site $site) {
            $site->domain = strtolower($site->domain);
        });
    }

    public function violations(): HasMany
    {
        return $this->hasMany(CspViolation::class);
    }
}
