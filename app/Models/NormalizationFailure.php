<?php

namespace App\Models;

use App\Tenancy\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NormalizationFailure extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'normalization_run_id',
        'ingestion_record_id',
        'error_code',
        'safe_diagnostics_json',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'safe_diagnostics_json' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function normalizationRun(): BelongsTo
    {
        return $this->belongsTo(NormalizationRun::class);
    }
}
