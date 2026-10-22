<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DentalRecordTooth extends Model
{


    protected $table = 'dental_record_teeth';

    protected $fillable = [
        'dental_record_id',
        'tooth_number',
        'tooth_status',
        'surfaces',
        'notes',
    ];

    protected $casts = [
        'surfaces' => 'array',
    ];

    public function dentalRecord(): BelongsTo
    {
        return $this->belongsTo(
            DentalRecord::class,
            'dental_record_id'
        );
    }
}
