<?php

namespace App\Filament\Resources\TreatmentPlanResource\Pages;

use App\Filament\Resources\TreatmentPlanResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTreatmentPlans extends ListRecords
{
    protected static string $resource = TreatmentPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
