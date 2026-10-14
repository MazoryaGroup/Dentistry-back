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

    protected static ?string $navigationIcon =
        'heroicon-o-squares-plus';

    protected static ?string $navigationLabel = 'خدمات';

    protected static ?string $modelLabel = 'خدمت';

    protected static ?string $pluralModelLabel = 'خدمات';

    protected static ?string $navigationGroup = 'Dentistry';

    /*
    |--------------------------------------------------------------------------
    | Form
    |--------------------------------------------------------------------------
    */

    public static function form(Form $form): Form
    {
        return $form->schema([

            /*
            |--------------------------------------------------------------------------
            | Language Tabs
            |--------------------------------------------------------------------------
            */

            Forms\Components\Tabs::make('service_languages')
                ->tabs([

                    /*
                    |--------------------------------------------------------------------------
                    | Persian
                    |--------------------------------------------------------------------------
                    */

                    Forms\Components\Tabs\Tab::make('فارسی')
                        ->icon('heroicon-o-language')
                        ->schema([

                            Forms\Components\Section::make(
                                'محتوای فارسی - بخش اول'
                            )
                                ->schema([

                                    Forms\Components\TextInput::make('name')
                                        ->label('عنوان اول (فارسی)')
                                        ->required()
                                        ->maxLength(255),

                                    Forms\Components\Textarea::make('description')
                                        ->label('توضیحات اول (فارسی)')
                                        ->rows(5)
                                        ->columnSpanFull(),

                                ])
                                ->columns(2),

                            Forms\Components\Section::make(
                                'محتوای فارسی - بخش دوم'
                            )
                                ->schema([

                                    Forms\Components\TextInput::make('title_2')
                                        ->label('عنوان دوم (فارسی)')
                                        ->maxLength(255),

                                    Forms\Components\Textarea::make('description_2')
                                        ->label('توضیحات دوم (فارسی)')
                                        ->rows(5)
                                        ->columnSpanFull(),

                                ])
                                ->columns(2),

                        ]),

                    /*
                    |--------------------------------------------------------------------------
                    | English
                    |--------------------------------------------------------------------------
                    */

                    Forms\Components\Tabs\Tab::make('English')
                        ->icon('heroicon-o-language')
                        ->schema([

                            Forms\Components\Section::make(
                                'English Translation'
                            )
                                ->relationship('englishTranslation')
                                ->schema([

                                    Forms\Components\Hidden::make('locale')
                                        ->default('en'),

                                    Forms\Components\TextInput::make('title_1')
                                        ->label('Title 1')
                                        ->maxLength(255),

                                    Forms\Components\Textarea::make('description_1')
                                        ->label('Description 1')
                                        ->rows(5)
                                        ->columnSpanFull(),

                                    Forms\Components\TextInput::make('title_2')
                                        ->label('Title 2')
                                        ->maxLength(255),

                                    Forms\Components\Textarea::make('description_2')
                                        ->label('Description 2')
                                        ->rows(5)
                                        ->columnSpanFull(),

                                ])
                                ->columns(2),

                        ]),

                ])
                ->activeTab(1)
                ->columnSpanFull(),

            /*
            |--------------------------------------------------------------------------
            | Service Images
            |--------------------------------------------------------------------------
            */

            Forms\Components\Section::make('تصاویر خدمت')
                ->description('تصاویر بین هر دو زبان مشترک هستند.')
                ->schema([

                    Forms\Components\FileUpload::make('image_1')
                        ->label('تصویر اول')
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
                        ->openable()
                        ->downloadable(),

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
                        ->openable()
                        ->downloadable(),

                ])
                ->columns(2)
                ->collapsible()
                ->columnSpanFull(),

            /*
            |--------------------------------------------------------------------------
            | Booking Information
            |--------------------------------------------------------------------------
            */

            Forms\Components\Section::make('اطلاعات رزرو و قیمت')
                ->schema([

//                    Forms\Components\TextInput::make('duration')
//                        ->label('مدت زمان (دقیقه)')
//                        ->numeric()
//                        ->minValue(1)
//                        ->required()
//                        ->default(60),
//
//                    Forms\Components\TextInput::make('price')
//                        ->label('قیمت (تومان)')
//                        ->numeric()
//                        ->minValue(0)
//                        ->required()
//                        ->default(0),
//
//                    Forms\Components\TextInput::make('deposit_amount')
//                        ->label('مبلغ بیعانه (تومان)')
//                        ->numeric()
//                        ->minValue(0)
//                        ->required()
//                        ->default(0),

                    Forms\Components\Toggle::make('is_active')
                        ->label('فعال')
                        ->default(true),

                ])
                ->columns(2)
                ->collapsible()
                ->columnSpanFull(),

            /*
            |--------------------------------------------------------------------------
            | Service Providers
            |--------------------------------------------------------------------------
            */

            Forms\Components\Section::make(
                'پزشکان ارائه‌دهنده خدمت'
            )
                ->description(
                    'انتخاب ارائه‌دهندگان و تعیین تعرفه اختصاصی'
                )
                ->schema([

                    Forms\Components\Repeater::make('staffServices')
                        ->label('پزشکان')
                        ->relationship('staffServices')
                        ->schema([

                            Forms\Components\Select::make('staff_id')
                                ->label('انتخاب پزشک')
                                ->relationship('staff', 'name')
                                ->searchable()
                                ->preload()
                                ->distinct()
                                ->required(),

//                            Forms\Components\TextInput::make('duration')
//                                ->label('مدت زمان (دقیقه)')
//                                ->numeric()
//                                ->minValue(1)
//                                ->required()
//                                ->default(60),
//
//                            Forms\Components\TextInput::make('price')
//                                ->label('تعرفه پزشک (تومان)')
//                                ->numeric()
//                                ->minValue(0)
//                                ->required()
//                                ->default(0),

                            Forms\Components\Toggle::make('is_active')
                                ->label('فعال')
                                ->default(true),

                        ])
                        ->columns(2)
                        ->defaultItems(0)
                        ->addActionLabel('افزودن پزشک')
                        ->collapsible()
                        ->reorderable(false)
                        ->columnSpanFull(),

                ])
                ->collapsible()
                ->columnSpanFull(),

        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Table
    |--------------------------------------------------------------------------
    */

    public static function table(Table $table): Table
    {
        return $table
            ->columns([

                Tables\Columns\TextColumn::make('id')
                    ->label('شناسه')
                    ->sortable(),

                Tables\Columns\ImageColumn::make('image_1')
                    ->label('تصویر اول')
                    ->disk('api_public')
                    ->size(55),

                Tables\Columns\TextColumn::make('name')
                    ->label('عنوان فارسی')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make(
                    'englishTranslation.title_1'
                )
                    ->label('عنوان انگلیسی')
                    ->placeholder('ترجمه نشده')
                    ->searchable(),

                Tables\Columns\ImageColumn::make('image_2')
                    ->label('تصویر دوم')
                    ->disk('api_public')
                    ->size(55),

                Tables\Columns\TextColumn::make('staff.name')
                    ->label('ارائه‌دهندگان')
                    ->badge()
                    ->separator(','),

                Tables\Columns\TextColumn::make('duration')
                    ->label('مدت زمان')
                    ->suffix(' دقیقه')
                    ->sortable(),

                Tables\Columns\TextColumn::make('price')
                    ->label('قیمت')
                    ->formatStateUsing(
                        fn ($state) =>
                            number_format((float) $state) . ' تومان'
                    )
                    ->sortable(),

                Tables\Columns\TextColumn::make('deposit_amount')
                    ->label('بیعانه')
                    ->formatStateUsing(
                        fn ($state) =>
                            number_format((float) $state) . ' تومان'
                    )
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('فعال')
                    ->boolean(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاریخ ایجاد')
                    ->formatStateUsing(
                        fn ($state) => $state
                            ? JalaliHelper::dateTime(
                                $state,
                                'Y/m/d H:i'
                            )
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

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    public static function getRelations(): array
    {
        return [];
    }

    /*
    |--------------------------------------------------------------------------
    | Pages
    |--------------------------------------------------------------------------
    */

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListServices::route('/'),
            'create' => Pages\CreateService::route('/create'),
            'edit' => Pages\EditService::route('/{record}/edit'),
        ];
    }
}
