<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DentalRecord extends Model
{
    use SoftDeletes;

    protected $table = 'dental_records';

    protected $fillable = [
        'client_id',
        'staff_id',
        'treatment_plan_id',
        'examined_at',
        'chief_complaint',
        'examination',
        'diagnosis',
        'patient_notes',
        'doctor_notes',
        'status',
        'is_visible_to_patient',
    ];

    protected $casts = [
        'examined_at' => 'datetime',
        'is_visible_to_patient' => 'boolean',
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

    // Related treatment plan
    public function treatmentPlan(): BelongsTo
    {
        return $this->belongsTo(
            TreatmentPlan::class,
            'treatment_plan_id'
        );
    }

    // Completed dental treatments
    public function treatments(): HasMany
    {
        return $this->hasMany(
            DentalRecordTreatment::class,
            'dental_record_id'
        )->orderBy('performed_at');
    }

    // Odontogram
    public function teeth(): HasMany
    {
        return $this->hasMany(
            DentalRecordTooth::class,
            'dental_record_id'
        )->orderBy('tooth_number');
    }

    // Medical images and documents
    public function attachments(): HasMany
    {
        return $this->hasMany(
            DentalRecordAttachment::class,
            'dental_record_id'
        );
    }
}
