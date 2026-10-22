<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TreatmentPlanResource\Pages;
use App\Models\TreatmentPlan;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class TreatmentPlanResource extends Resource
{
    protected static ?string $model = TreatmentPlan::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'طرح‌های درمان';

    protected static ?string $modelLabel = 'طرح درمان';

    protected static ?string $pluralModelLabel = 'طرح‌های درمان';

    protected static ?string $navigationGroup = 'Dentistry';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form->schema([

            Forms\Components\Section::make('اطلاعات طرح درمان')
                ->schema([

                    Forms\Components\Select::make('client_id')
                        ->label('بیمار')
                        ->relationship(
                            name: 'client',
                            titleAttribute: 'name',
                            modifyQueryUsing: fn ($query) => $query
                                ->whereNotNull('name')
                                ->where('name', '!=', '')
                        )
                        ->searchable()
                        ->preload()
                        ->required(),

                    Forms\Components\Select::make('staff_id')
                        ->label('پزشک')
                        ->relationship(
                            name: 'staff',
                            titleAttribute: 'name',
                            modifyQueryUsing: fn ($query) => $query
                                ->whereNotNull('name')
                                ->where('name', '!=', '')
                        )
                        ->searchable()
                        ->preload()
                        ->required(),

                    Forms\Components\TextInput::make('title')
                        ->label('عنوان طرح درمان')
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull(),

                    Forms\Components\Textarea::make('diagnosis')
                        ->label('تشخیص')
                        ->rows(3)
                        ->columnSpanFull(),

                    Forms\Components\Textarea::make('description')
                        ->label('شرح درمان')
                        ->rows(4)
                        ->columnSpanFull(),

                    Forms\Components\Select::make('status')
                        ->label('وضعیت طرح درمان')
                        ->options([
                            'draft' => 'پیش‌نویس',
                            'active' => 'فعال',
                            'completed' => 'تکمیل‌شده',
                            'cancelled' => 'لغوشده',
                        ])
                        ->default('draft')
                        ->required(),

                    Forms\Components\DatePicker::make('planned_start_date')
                        ->label('تاریخ برنامه‌ریزی‌شده شروع درمان'),

                    Forms\Components\DateTimePicker::make('completed_at')
                        ->label('تاریخ و ساعت تکمیل درمان')
                        ->seconds(false),

                ])
                ->columns(2),

            Forms\Components\Section::make('مراحل درمان')
                ->description('اقدامات و مراحل موردنیاز برای اجرای این طرح درمان را اضافه کنید.')
                ->schema([

                    Forms\Components\Repeater::make('items')
                        ->label('مراحل طرح درمان')
                        ->relationship('items')
                        ->schema([

                            Forms\Components\TextInput::make('title')
                                ->label('عنوان درمان')
                                ->required()
                                ->maxLength(255)
                                ->columnSpanFull(),

                            Forms\Components\Select::make('service_id')
                                ->label('خدمت مرتبط')
                                ->relationship(
                                    name: 'service',
                                    titleAttribute: 'name',
                                    modifyQueryUsing: fn ($query) => $query
                                        ->whereNotNull('name')
                                        ->where('name', '!=', '')
                                )
                                ->searchable()
                                ->preload()
                                ->nullable(),

                            Forms\Components\TextInput::make('tooth_number')
                                ->label('شماره دندان')
                                ->placeholder('مثلاً: 16')
                                ->maxLength(10),

                            Forms\Components\Textarea::make('description')
                                ->label('شرح مرحله درمان')
                                ->rows(3)
                                ->columnSpanFull(),

                            Forms\Components\TextInput::make('estimated_cost')
                                ->label('هزینه تخمینی')
                                ->numeric()
                                ->minValue(0)
                                ->default(0)
                                ->required(),

                            Forms\Components\Select::make('status')
                                ->label('وضعیت مرحله درمان')
                                ->options([
                                    'pending' => 'در انتظار انجام',
                                    'in_progress' => 'در حال انجام',
                                    'completed' => 'تکمیل‌شده',
                                    'cancelled' => 'لغوشده',
                                ])
                                ->default('pending')
                                ->required(),

                            Forms\Components\DateTimePicker::make('started_at')
                                ->label('تاریخ و ساعت شروع')
                                ->seconds(false),

                            Forms\Components\DateTimePicker::make('completed_at')
                                ->label('تاریخ و ساعت تکمیل')
                                ->seconds(false),

                        ])
                        ->columns(2)
                        ->defaultItems(0)
                        ->addActionLabel('افزودن مرحله درمان')
                        ->collapsible()
                        ->cloneable()
                        ->reorderable()
                        ->orderColumn('sort_order')
                        ->columnSpanFull(),

                ]),

        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([

                Tables\Columns\TextColumn::make('id')
                    ->label('شناسه')
                    ->sortable(),

                Tables\Columns\TextColumn::make('client.name')
                    ->label('بیمار')
                    ->searchable(),

                Tables\Columns\TextColumn::make('staff.name')
                    ->label('پزشک')
                    ->searchable(),

                Tables\Columns\TextColumn::make('title')
                    ->label('طرح درمان')
                    ->searchable()
                    ->limit(40),

                Tables\Columns\TextColumn::make('status')
                    ->label('وضعیت')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'draft' => 'پیش‌نویس',
                        'active' => 'فعال',
                        'completed' => 'تکمیل‌شده',
                        'cancelled' => 'لغوشده',
                        default => $state ?? 'نامشخص',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'draft' => 'gray',
                        'active' => 'info',
                        'completed' => 'success',
                        'cancelled' => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('items_count')
                    ->label('تعداد مراحل')
                    ->counts('items'),

                Tables\Columns\TextColumn::make('planned_start_date')
                    ->label('تاریخ شروع')
                    ->date('Y-m-d'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاریخ ثبت')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),

            ])
            ->defaultSort('id', 'desc')
            ->filters([

                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'draft' => 'پیش‌نویس',
                        'active' => 'فعال',
                        'completed' => 'تکمیل‌شده',
                        'cancelled' => 'لغوشده',
                    ]),

            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTreatmentPlans::route('/'),
            'create' => Pages\CreateTreatmentPlan::route('/create'),
            'edit' => Pages\EditTreatmentPlan::route('/{record}/edit'),
        ];
    }
}
