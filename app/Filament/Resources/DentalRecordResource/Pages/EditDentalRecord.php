<?php

namespace App\Filament\Resources\DentalRecordResource\Pages;

use App\Filament\Resources\DentalRecordResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDentalRecord extends EditRecord
{
    protected static string $resource = DentalRecordResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
