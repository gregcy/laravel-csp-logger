<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UnauthorizedReportDomainResource\Pages;
use App\Models\Site;
use App\Models\UnauthorizedReportDomain;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UnauthorizedReportDomainResource extends Resource
{
    protected static ?string $model = UnauthorizedReportDomain::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationLabel = 'Unauthorized Domains';

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('last_seen_at', 'desc')
            ->columns([
                TextColumn::make('domain')->searchable()->sortable(),
                TextColumn::make('occurrence_count')->label('Count')->sortable(),
                TextColumn::make('first_seen_at')->dateTime()->sortable(),
                TextColumn::make('last_seen_at')->dateTime()->sortable(),
            ])
            ->recordActions([
                Action::make('registerAsSite')
                    ->label('Register as Site')
                    ->icon('heroicon-o-check-circle')
                    ->schema([
                        TextInput::make('name')->label('Site name (optional)'),
                    ])
                    ->action(function (UnauthorizedReportDomain $record, array $data): void {
                        Site::create([
                            'domain' => $record->domain,
                            'name' => $data['name'] ?? null,
                            'is_active' => true,
                        ]);
                    })
                    ->visible(fn (UnauthorizedReportDomain $record) => ! Site::where('domain', $record->domain)->exists()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUnauthorizedReportDomains::route('/'),
        ];
    }
}
