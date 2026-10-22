<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DentalRecordTreatment extends Model
{
    use SoftDeletes;

    protected $table = 'dental_record_treatments';

    protected $fillable = [
        'dental_record_id',
        'service_id',
        'treatment_plan_item_id',
        'performed_by_staff_id',
        'title',
        'description',
        'tooth_number',
        'performed_at',
    ];

    protected $casts = [
        'performed_at' => 'datetime',
    ];

    public function dentalRecord(): BelongsTo
    {
        return $this->belongsTo(
            DentalRecord::class,
            'dental_record_id'
        );
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(
            Service::class,
            'service_id'
        );
    }

    public function treatmentPlanItem(): BelongsTo
    {
        return $this->belongsTo(
            TreatmentPlanItem::class,
            'treatment_plan_item_id'
        );
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(
            Staff::class,
            'performed_by_staff_id'
        );
    }
}
