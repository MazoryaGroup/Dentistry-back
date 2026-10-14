<?php


namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceTranslation extends Model
{
    protected $table = 'service_translations';

    protected $fillable = [
        'service_id',
        'locale',
        'title_1',
        'description_1',
        'title_2',
        'description_2',
    ];

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
