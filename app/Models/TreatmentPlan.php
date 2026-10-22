<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TreatmentPlan extends Model
{
    use SoftDeletes;

    protected $table = 'treatment_plans';

    protected $fillable = [
        'client_id',
        'staff_id',
        'title',
        'diagnosis',
        'description',
        'status',
        'planned_start_date',
        'completed_at',
    ];

    protected $casts = [
        'planned_start_date' => 'date',
        'completed_at' => 'datetime',
    ];

    // Patient
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    // Doctor
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'staff_id');
    }

    // Treatment steps
    public function items(): HasMany
    {
        return $this->hasMany(
            TreatmentPlanItem::class,
            'treatment_plan_id'
        )->orderBy('sort_order');
    }
}
