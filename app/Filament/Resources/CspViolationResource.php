<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CspViolationResource\Pages;
use App\Models\CspViolation;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CspViolationResource extends Resource
{
    protected static ?string $model = CspViolation::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shield-exclamation';

    private const DIRECTIVES = [
        'base-uri' => 'base-uri',
        'child-src' => 'child-src',
        'connect-src' => 'connect-src',
        'default-src' => 'default-src',
        'font-src' => 'font-src',
        'form-action' => 'form-action',
        'frame-ancestors' => 'frame-ancestors',
        'frame-src' => 'frame-src',
        'img-src' => 'img-src',
        'manifest-src' => 'manifest-src',
        'media-src' => 'media-src',
        'object-src' => 'object-src',
        'script-src' => 'script-src',
        'script-src-elem' => 'script-src-elem',
        'script-src-attr' => 'script-src-attr',
        'style-src' => 'style-src',
        'style-src-elem' => 'style-src-elem',
        'style-src-attr' => 'style-src-attr',
        'worker-src' => 'worker-src',
    ];

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('last_seen_at', 'desc')
            ->columns([
                TextColumn::make('site.domain')->label('Site')->sortable()->searchable(),
                TextColumn::make('effective_directive')->label('Directive')->sortable(),
                TextColumn::make('blocked_uri')->label('Blocked URI')->limit(60)->searchable(),
                TextColumn::make('disposition')->badge(),
                TextColumn::make('occurrence_count')->label('Count')->sortable(),
                TextColumn::make('first_seen_at')->dateTime()->sortable(),
                TextColumn::make('last_seen_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('site_id')
                    ->label('Site')
                    ->relationship('site', 'domain')
                    ->searchable(),
                SelectFilter::make('effective_directive')
                    ->label('Directive')
                    ->options(self::DIRECTIVES),
                SelectFilter::make('disposition')
                    ->options([
                        'enforce' => 'Enforce',
                        'report' => 'Report',
                    ]),
                Filter::make('blocked_uri')
                    ->schema([
                        TextInput::make('value')->label('Blocked URI contains'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $q, string $value) => $q->where('blocked_uri', 'like', "%{$value}%"),
                    )),
                Filter::make('last_seen_at')
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $q, string $d) => $q->whereDate('last_seen_at', '>=', $d))
                        ->when($data['until'] ?? null, fn (Builder $q, string $d) => $q->whereDate('last_seen_at', '<=', $d))),
            ])
            ->recordActions([
                Action::make('viewRaw')
                    ->label('View raw')
                    ->color('gray')
                    ->modalHeading('Raw CSP report')
                    ->schema([
                        TextEntry::make('raw_sample')
                            ->label('Raw payload')
                            ->formatStateUsing(fn ($state) => json_encode($state, JSON_PRETTY_PRINT))
                            ->columnSpanFull(),
                    ])
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCspViolations::route('/'),
        ];
    }
}
