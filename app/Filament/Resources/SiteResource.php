<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SiteResource\Pages;
use App\Models\Site;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class SiteResource extends Resource
{
    protected static ?string $model = Site::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-globe-alt';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('domain')
                ->required()
                ->unique(ignoreRecord: true)
                ->helperText('Exact hostname, e.g. www.example.com'),
            TextInput::make('name')
                ->helperText('Friendly label, optional'),
            Toggle::make('is_active')
                ->default(true)
                ->helperText('Inactive sites\' reports are treated as unauthorized'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultPaginationPageOption(50)
            ->columns([
                TextColumn::make('domain')->searchable()->sortable(),
                TextColumn::make('name'),
                ToggleColumn::make('is_active'),
                TextColumn::make('violations_count')
                    ->counts('violations')
                    ->label('Violations'),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->recordActions([
                \Filament\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSites::route('/'),
            'create' => Pages\CreateSite::route('/create'),
            'edit' => Pages\EditSite::route('/{record}/edit'),
        ];
    }
}
