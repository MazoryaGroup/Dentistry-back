<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class DentalRecordAttachment extends Model
{
    use SoftDeletes;

    protected $table = 'dental_record_attachments';

    protected $fillable = [
        'dental_record_id',
        'staff_id',
        'title',
        'attachment_type',
        'file_path',
        'disk',
        'original_name',
        'mime_type',
        'file_size',
        'is_visible_to_patient',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'is_visible_to_patient' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $attachment): void {

            $attachment->disk = 'local';

            $path = $attachment->file_path;

            if (!is_string($path) || $path === '') {
                return;
            }

            $disk = Storage::disk('local');

            if (!$disk->exists($path)) {
                return;
            }

            // Read metadata from the stored file.
            $attachment->mime_type = $disk->mimeType($path);
            $attachment->file_size = $disk->size($path);

            // original_name is populated by Filament
            // using storeFileNamesIn().
        });
    }

    public function dentalRecord(): BelongsTo
    {
        return $this->belongsTo(
            DentalRecord::class,
            'dental_record_id'
        );
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(
            Staff::class,
            'staff_id'
        );
    }
}
