<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Service extends Model
{
    protected $table = 'services';

    protected $fillable = [
        'name',
        'description',
        'title_2',
        'description_2',
        'duration',
        'price',
        'deposit_amount',
        'is_active',
        'image_1',
        'image_2',
    ];

    protected $casts = [
        'duration' => 'integer',
        'price' => 'decimal:2',
        'deposit_amount' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saved(function (Service $service) {
            $service->translations()->updateOrCreate(
                ['locale' => 'fa'],
                [
                    'title_1' => $service->name,
                    'description_1' => $service->description,
                    'title_2' => $service->title_2,
                    'description_2' => $service->description_2,
                ]
            );
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Translations
    |--------------------------------------------------------------------------
    */

    public function translations(): HasMany
    {
        return $this->hasMany(ServiceTranslation::class);
    }

    public function persianTranslation(): HasOne
    {
        return $this->hasOne(ServiceTranslation::class)
            ->where('locale', 'fa');
    }

    public function englishTranslation(): HasOne
    {
        return $this->hasOne(ServiceTranslation::class)
            ->where('locale', 'en');
    }

    /*
    |--------------------------------------------------------------------------
    | Existing NIL Relations
    |--------------------------------------------------------------------------
    */

    public function staffServices(): HasMany
    {
        return $this->hasMany(StaffService::class);
    }

    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(
            Staff::class,
            'staff_services'
        )->withPivot([
            'duration',
            'price',
            'is_active',
        ])->withTimestamps();
    }

    public function bookingServices(): HasMany
    {
        return $this->hasMany(BookingService::class);
    }
}
