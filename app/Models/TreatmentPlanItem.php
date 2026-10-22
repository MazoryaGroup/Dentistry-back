<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TreatmentPlanItem extends Model
{
    use SoftDeletes;

    protected $table = 'treatment_plan_items';

    protected $fillable = [
        'treatment_plan_id',
        'service_id',
        'title',
        'description',
        'tooth_number',
        'sort_order',
        'estimated_cost',
        'status',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'estimated_cost' => 'decimal:2',
        'sort_order' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    // Parent Treatment Plan
    public function treatmentPlan(): BelongsTo
    {
        return $this->belongsTo(
            TreatmentPlan::class,
            'treatment_plan_id'
        );
    }

    // Related Service
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_id');
    }
}
