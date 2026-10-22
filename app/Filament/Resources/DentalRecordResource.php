<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DentalRecordResource\Pages;
use App\Models\DentalRecord;
use App\Models\TreatmentPlan;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DentalRecordResource extends Resource
{
    protected static ?string $model = DentalRecord::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'پرونده‌های دندان‌پزشکی';

    protected static ?string $modelLabel = 'پرونده دندان‌پزشکی';

    protected static ?string $pluralModelLabel = 'پرونده‌های دندان‌پزشکی';

    protected static ?string $navigationGroup = 'Dentistry';

    protected static ?int $navigationSort = 4;

    /*
    |--------------------------------------------------------------------------
    | شماره‌گذاری دندان‌ها بر اساس استاندارد FDI
    |--------------------------------------------------------------------------
    */

    private static function toothOptions(): array
    {
        $options = [];

        // دندان‌های دائمی: ۱۱ تا ۴۸
        foreach ([1, 2, 3, 4] as $quadrant) {
            foreach (range(1, 8) as $tooth) {
                $number = (string) ($quadrant * 10 + $tooth);
                $options[$number] = 'دندان ' . $number;
            }
        }

        // دندان‌های شیری: ۵۱ تا ۸۵
        foreach ([5, 6, 7, 8] as $quadrant) {
            foreach (range(1, 5) as $tooth) {
                $number = (string) ($quadrant * 10 + $tooth);
                $options[$number] = 'دندان ' . $number;
            }
        }

        return $options;
    }

    /*
    |--------------------------------------------------------------------------
    | فرم
    |--------------------------------------------------------------------------
    */

    public static function form(Form $form): Form
    {
        return $form->schema([

            /*
            |--------------------------------------------------------------------------
            | جزئیات معاینه
            |--------------------------------------------------------------------------
            */

            Forms\Components\Section::make('اطلاعات معاینه')
                ->schema([

                    Forms\Components\Select::make('client_id')
                        ->label('بیمار')
                        ->relationship(
                            name: 'client',
                            titleAttribute: 'name',
                            modifyQueryUsing: fn (Builder $query) =>
                            $query->whereNotNull('name')
                                ->where('name', '!=', '')
                        )
                        ->searchable()
                        ->preload()
                        ->live()
                        ->afterStateUpdated(
                            fn (Set $set) =>
                            $set('treatment_plan_id', null)
                        )
                        ->required(),

                    Forms\Components\Select::make('staff_id')
                        ->label('پزشک')
                        ->relationship(
                            name: 'staff',
                            titleAttribute: 'name',
                            modifyQueryUsing: fn (Builder $query) =>
                            $query->whereNotNull('name')
                                ->where('name', '!=', '')
                        )
                        ->searchable()
                        ->preload()
                        ->required(),

                    Forms\Components\Select::make('treatment_plan_id')
                        ->label('طرح درمان مرتبط')
                        ->options(
                            fn (Get $get): array =>
                            TreatmentPlan::query()
                                ->where('client_id', $get('client_id'))
                                ->pluck('title', 'id')
                                ->all()
                        )
                        ->searchable()
                        ->nullable(),

                    Forms\Components\DateTimePicker::make('examined_at')
                        ->label('تاریخ معاینه')
                        ->default(now())
                        ->seconds(false)
                        ->required(),

                    Forms\Components\Select::make('status')
                        ->label('وضعیت پرونده')
                        ->options([
                            'draft' => 'پیش‌نویس',
                            'finalized' => 'نهایی‌شده',
                            'archived' => 'بایگانی‌شده',
                        ])
                        ->default('draft')
                        ->required(),

                    Forms\Components\Toggle::make('is_visible_to_patient')
                        ->label('قابل مشاهده برای بیمار')
                        ->default(false),

                    Forms\Components\Textarea::make('chief_complaint')
                        ->label('شکایت اصلی بیمار')
                        ->rows(3)
                        ->columnSpanFull(),

                    Forms\Components\Textarea::make('examination')
                        ->label('معاینه بالینی')
                        ->rows(4)
                        ->columnSpanFull(),

                    Forms\Components\Textarea::make('diagnosis')
                        ->label('تشخیص')
                        ->rows(4)
                        ->columnSpanFull(),

                    Forms\Components\Textarea::make('patient_notes')
                        ->label('یادداشت‌های بیمار')
                        ->rows(3)
                        ->columnSpanFull(),

                    Forms\Components\Textarea::make('doctor_notes')
                        ->label('یادداشت‌های خصوصی پزشک')
                        ->helperText(
                            'یادداشت‌های محرمانه پزشکی؛ این اطلاعات هرگز نباید در APIهای بیمار نمایش داده شوند.'
                        )
                        ->rows(4)
                        ->columnSpanFull(),

                ])
                ->columns(2),

            /*
            |--------------------------------------------------------------------------
            | درمان‌های انجام‌شده
            |--------------------------------------------------------------------------
            */

            Forms\Components\Section::make('درمان‌های انجام‌شده')
                ->description(
                    'اقدامات درمانی انجام‌شده برای بیمار را ثبت کنید.'
                )
                ->collapsed()
                ->schema([

                    Forms\Components\Repeater::make('treatments')
                        ->label('درمان‌های انجام‌شده')
                        ->relationship('treatments')
                        ->schema([

                            Forms\Components\TextInput::make('title')
                                ->label('درمان')
                                ->required()
                                ->maxLength(255)
                                ->columnSpanFull(),

                            Forms\Components\Select::make('service_id')
                                ->label('خدمت مرتبط')
                                ->relationship(
                                    name: 'service',
                                    titleAttribute: 'name',
                                    modifyQueryUsing: fn (Builder $query) =>
                                    $query->whereNotNull('name')
                                        ->where('name', '!=', '')
                                )
                                ->searchable()
                                ->preload(),

                            Forms\Components\Select::make('performed_by_staff_id')
                                ->label('انجام‌شده توسط')
                                ->relationship(
                                    name: 'staff',
                                    titleAttribute: 'name',
                                    modifyQueryUsing: fn (Builder $query) =>
                                    $query->whereNotNull('name')
                                        ->where('name', '!=', '')
                                )
                                ->searchable()
                                ->preload(),

                            Forms\Components\Select::make('tooth_number')
                                ->label('شماره دندان')
                                ->options(self::toothOptions())
                                ->searchable(),

                            Forms\Components\DateTimePicker::make('performed_at')
                                ->label('تاریخ انجام')
                                ->default(now())
                                ->seconds(false)
                                ->required(),

                            Forms\Components\Textarea::make('description')
                                ->label('جزئیات اقدام درمانی')
                                ->rows(3)
                                ->columnSpanFull(),

                        ])
                        ->columns(2)
                        ->defaultItems(0)
                        ->addActionLabel('افزودن درمان')
                        ->collapsible()
                        ->reorderable(false)
                        ->columnSpanFull(),

                ]),

            /*
            |--------------------------------------------------------------------------
            | اودونتوگرام
            |--------------------------------------------------------------------------
            */

            Forms\Components\Section::make('اودونتوگرام (نمودار دندان‌ها)')
                ->description(
                    'وضعیت هر دندان را به‌صورت جداگانه ثبت کنید.'
                )
                ->collapsed()
                ->schema([

                    Forms\Components\Repeater::make('teeth')
                        ->label('سوابق دندان‌ها')
                        ->relationship('teeth')
                        ->schema([

                            Forms\Components\Select::make('tooth_number')
                                ->label('شماره دندان')
                                ->options(self::toothOptions())
                                ->searchable()
                                ->distinct()
                                ->required(),

                            Forms\Components\Select::make('tooth_status')
                                ->label('وضعیت دندان')
                                ->options([
                                    'healthy' => 'سالم',
                                    'caries' => 'پوسیدگی',
                                    'filled' => 'پرشده',
                                    'missing' => 'ازدست‌رفته',
                                    'crown' => 'روکش‌شده',
                                    'root_canal' => 'عصب‌کشی‌شده',
                                    'implant' => 'ایمپلنت',
                                    'extraction_needed' => 'نیازمند کشیدن',
                                ])
                                ->default('healthy')
                                ->required(),

                            Forms\Components\KeyValue::make('surfaces')
                                ->label('سطوح دندان')
                                ->keyLabel('سطح')
                                ->valueLabel('وضعیت')
                                ->helperText(
                                    'مثال: مزیال، دیستال، اکلوزال، باکال، لینگوال'
                                )
                                ->columnSpanFull(),

                            Forms\Components\Textarea::make('notes')
                                ->label('یادداشت‌های دندان')
                                ->rows(3)
                                ->columnSpanFull(),

                        ])
                        ->columns(2)
                        ->defaultItems(0)
                        ->addActionLabel('افزودن دندان')
                        ->collapsible()
                        ->reorderable(false)
                        ->cloneable(false)
                        ->deletable(false)
                        ->columnSpanFull(),

                ]),

            /*
            |--------------------------------------------------------------------------
            | پیوست‌ها
            |--------------------------------------------------------------------------
            */

            Forms\Components\Section::make('پیوست‌های پزشکی')
                ->description(
                    'تصاویر پزشکی، رادیوگرافی‌ها و اسناد محرمانه.'
                )
                ->collapsed()
                ->schema([

                    Forms\Components\Repeater::make('attachments')
                        ->label('پیوست‌ها')
                        ->relationship('attachments')
                        ->schema([

                            Forms\Components\TextInput::make('title')
                                ->label('عنوان سند')
                                ->required()
                                ->maxLength(255)
                                ->columnSpanFull(),

                            Forms\Components\Select::make('attachment_type')
                                ->label('نوع پیوست')
                                ->options([
                                    'xray' => 'عکس رادیولوژی دندان',
                                    'radiograph' => 'رادیوگرافی',
                                    'intraoral_image' => 'تصویر داخل دهان',
                                    'clinical_photo' => 'عکس بالینی',
                                    'medical_document' => 'سند پزشکی',
                                    'other' => 'سایر',
                                ])
                                ->required(),

                            Forms\Components\Select::make('staff_id')
                                ->label('بارگذاری‌شده توسط')
                                ->relationship(
                                    name: 'staff',
                                    titleAttribute: 'name',
                                    modifyQueryUsing: fn (Builder $query) =>
                                    $query->whereNotNull('name')
                                        ->where('name', '!=', '')
                                )
                                ->searchable()
                                ->preload(),

                            Forms\Components\FileUpload::make('file_path')
                                ->label('فایل پزشکی')
                                ->disk('local')
                                ->directory('dental-records')
                                ->visibility('private')
                                ->storeFileNamesIn('original_name')
                                ->acceptedFileTypes([
                                    'image/jpeg',
                                    'image/png',
                                    'image/webp',
                                    'application/pdf',
                                ])
                                ->maxSize(10240)
                                ->previewable(false)
                                ->required()
                                ->columnSpanFull(),

                            Forms\Components\Hidden::make('original_name'),

                            Forms\Components\Toggle::make('is_visible_to_patient')
                                ->label('قابل مشاهده برای بیمار')
                                ->default(false),

                        ])
                        ->columns(2)
                        ->defaultItems(0)
                        ->addActionLabel('افزودن پیوست')
                        ->collapsible()
                        ->reorderable(false)
                        ->columnSpanFull(),

                ]),

        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | جدول
    |--------------------------------------------------------------------------
    */

    public static function table(Table $table): Table
    {
        return $table
            ->columns([

                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                Tables\Columns\TextColumn::make('client.name')
                    ->label('بیمار')
                    ->searchable(),

                Tables\Columns\TextColumn::make('staff.name')
                    ->label('پزشک')
                    ->searchable(),

                Tables\Columns\TextColumn::make('examined_at')
                    ->label('تاریخ معاینه')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('diagnosis')
                    ->label('تشخیص')
                    ->limit(40),

                Tables\Columns\TextColumn::make('status')
                    ->label('وضعیت')
                    ->badge()
                    ->color(
                        fn (string $state): string => match ($state) {
                            'draft' => 'gray',
                            'finalized' => 'success',
                            'archived' => 'warning',
                            default => 'gray',
                        }
                    ),

                Tables\Columns\IconColumn::make('is_visible_to_patient')
                    ->label('دسترسی بیمار')
                    ->boolean(),

                Tables\Columns\TextColumn::make('treatments_count')
                    ->label('درمان‌ها')
                    ->counts('treatments'),

                Tables\Columns\TextColumn::make('teeth_count')
                    ->label('دندان‌ها')
                    ->counts('teeth'),

                Tables\Columns\TextColumn::make('attachments_count')
                    ->label('پیوست‌ها')
                    ->counts('attachments'),

            ])
            ->defaultSort('examined_at', 'desc')
            ->filters([

                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'draft' => 'پیش‌نویس',
                        'finalized' => 'نهایی‌شده',
                        'archived' => 'بایگانی‌شده',
                    ]),

                Tables\Filters\TernaryFilter::make('is_visible_to_patient')
                    ->label('قابل مشاهده برای بیمار'),

            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([]);
    }

    /*
    |--------------------------------------------------------------------------
    | صفحات
    |--------------------------------------------------------------------------
    */

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDentalRecords::route('/'),
            'create' => Pages\CreateDentalRecord::route('/create'),
            'edit' => Pages\EditDentalRecord::route('/{record}/edit'),
        ];
    }
}
