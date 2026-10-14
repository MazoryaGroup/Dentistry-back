<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ServiceResource\Pages;
use App\Helpers\JalaliHelper;
use App\Models\Service;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ServiceResource extends Resource
{
    protected static ?string $model = Service::class;

    protected static ?string $navigationIcon = 'heroicon-o-squares-plus';

    protected static ?string $navigationLabel = 'خدمات';

    protected static ?string $modelLabel = 'خدمت';

    protected static ?string $pluralModelLabel = 'خدمات';

    protected static ?string $navigationGroup = 'Dentistry';

    public static function form(Form $form): Form
    {
        return $form->schema([

            /*
            |--------------------------------------------------------------------------
            | Service Images
            |--------------------------------------------------------------------------
            */

            Forms\Components\Section::make('تصاویر خدمات')
                ->schema([

                    Forms\Components\FileUpload::make('image_1')
                        ->label('تصویر اصلی')
                        ->image()
                        ->disk('api_public')
                        ->directory('service')
                        ->visibility('public')
                        ->acceptedFileTypes([
                            'image/jpeg',
                            'image/png',
                            'image/webp',
                        ])
                        ->maxSize(5120)
                        ->imagePreviewHeight('250')
                        ->downloadable()
                        ->openable(),

                    Forms\Components\FileUpload::make('image_2')
                        ->label('تصویر دوم')
                        ->image()
                        ->disk('api_public')
                        ->directory('service')
                        ->visibility('public')
                        ->acceptedFileTypes([
                            'image/jpeg',
                            'image/png',
                            'image/webp',
                        ])
                        ->maxSize(5120)
                        ->imagePreviewHeight('250')
                        ->downloadable()
                        ->openable(),

                ])
                ->columns(2)
                ->columnSpanFull(),

            /*
            |--------------------------------------------------------------------------
            | Service Information
            |--------------------------------------------------------------------------
            */

            Forms\Components\Section::make('اطلاعات خدمت')
                ->schema([

                    Forms\Components\TextInput::make('name')
                        ->label('نام خدمت')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\Textarea::make('description')
                        ->label('توضیحات')
                        ->rows(4)
                        ->columnSpanFull(),

                    Forms\Components\TextInput::make('duration')
                        ->label('مدت زمان (دقیقه)')
                        ->numeric()
                        ->minValue(1)
                        ->required()
                        ->default(60),

                    Forms\Components\TextInput::make('price')
                        ->label('قیمت (تومان)')
                        ->numeric()
                        ->minValue(0)
                        ->required()
                        ->default(0),

                    Forms\Components\TextInput::make('deposit_amount')
                        ->label('مبلغ بیعانه (تومان)')
                        ->numeric()
                        ->minValue(0)
                        ->required()
                        ->default(0),

                    Forms\Components\Toggle::make('is_active')
                        ->label('فعال')
                        ->default(true),

                ])
                ->columns(2)
                ->columnSpanFull(),

        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([

                Tables\Columns\TextColumn::make('id')
                    ->label('شناسه')
                    ->sortable(),

                /*
                |--------------------------------------------------------------------------
                | Images
                |--------------------------------------------------------------------------
                */

                Tables\Columns\ImageColumn::make('image_1')
                    ->label('تصویر اصلی')
                    ->disk('api_public')
                    ->size(60),

                Tables\Columns\ImageColumn::make('image_2')
                    ->label('تصویر دوم')
                    ->disk('api_public')
                    ->size(60),

                /*
                |--------------------------------------------------------------------------
                | Service Information
                |--------------------------------------------------------------------------
                */

                Tables\Columns\TextColumn::make('name')
                    ->label('نام خدمت')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('duration')
                    ->label('مدت زمان')
                    ->suffix(' دقیقه')
                    ->sortable(),

                Tables\Columns\TextColumn::make('price')
                    ->label('قیمت')
                    ->formatStateUsing(
                        fn ($state) => number_format((float) $state) . ' تومان'
                    )
                    ->sortable(),

                Tables\Columns\TextColumn::make('deposit_amount')
                    ->label('بیعانه')
                    ->formatStateUsing(
                        fn ($state) => number_format((float) $state) . ' تومان'
                    )
                    ->sortable(),

                /*
                |--------------------------------------------------------------------------
                | Status
                |--------------------------------------------------------------------------
                */

                Tables\Columns\IconColumn::make('is_active')
                    ->label('فعال')
                    ->boolean(),

                /*
                |--------------------------------------------------------------------------
                | Created At - Jalali
                |--------------------------------------------------------------------------
                */

                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاریخ ایجاد')
                    ->formatStateUsing(
                        fn ($state) => $state
                            ? JalaliHelper::dateTime($state, 'Y/m/d H:i')
                            : '-'
                    )
                    ->sortable(),

            ])
            ->filters([

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('وضعیت فعال'),

            ])
            ->actions([

                Tables\Actions\EditAction::make()
                    ->label('ویرایش'),

                Tables\Actions\DeleteAction::make()
                    ->label('حذف'),

            ])
            ->bulkActions([

                Tables\Actions\BulkActionGroup::make([

                    Tables\Actions\DeleteBulkAction::make()
                        ->label('حذف گروهی'),

                ]),

            ])
            ->defaultSort('id', 'desc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListServices::route('/'),
            'create' => Pages\CreateService::route('/create'),
            'edit' => Pages\EditService::route('/{record}/edit'),
        ];
    }
}
