<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AiModelResource\Pages;
use App\Models\AiModel;
use Filament\Actions;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AiModelResource extends Resource
{
    protected static ?string $model = AiModel::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cpu-chip';

    protected static ?string $navigationLabel = 'AI Models';

    protected static ?string $modelLabel = 'AI Model';

    protected static ?string $pluralModelLabel = 'AI Models';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('external_id')
                    ->required()
                    ->maxLength(255),
                TextInput::make('canonical_slug')
                    ->maxLength(255),
                TextInput::make('context_length')
                    ->numeric(),
                TextInput::make('pricing_prompt'),
                TextInput::make('pricing_completion'),
                Toggle::make('is_enabled')
                    ->label('Enabled'),
                Textarea::make('description'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('aiProvider.name')
                    ->label('Provider')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('external_id')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('context_length')
                    ->numeric()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('pricing')
                    ->label('Pricing ($/1M tokens)')
                    ->getStateUsing(fn (AiModel $record): string => self::formatPricing($record))
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderByRaw("(COALESCE(NULLIF(pricing_prompt, -1), 0) + COALESCE(NULLIF(pricing_completion, -1), 0)) {$direction}"))
                    ->toggleable(),
                TextColumn::make('expiration_date')
                    ->date()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('is_enabled')
                    ->label('Enabled')
                    ->badge()
                    ->color(fn (bool $state): string => $state ? 'success' : 'warning')
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Yes' : 'No')
                    ->sortable(),
                TextColumn::make('api_created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([10, 25, 50, 100])
            ->filters([
                Tables\Filters\Filter::make('enabled')
                    ->query(fn (Builder $query): Builder => $query->where('is_enabled', true)),
                Tables\Filters\Filter::make('free')
                    ->query(fn (Builder $query): Builder => $query->where('pricing_prompt', '0')->where('pricing_completion', '0')),
                Tables\Filters\Filter::make('expired')
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('expiration_date')->where('expiration_date', '<=', now())),
            ])
            ->recordActions([
                Actions\Action::make('toggleEnabled')
                    ->label(fn (AiModel $record): string => $record->is_enabled ? 'Disable' : 'Enable')
                    ->icon(fn (AiModel $record): string => $record->is_enabled ? 'heroicon-o-x-circle' : 'heroicon-o-check-circle')
                    ->color(fn (AiModel $record): string => $record->is_enabled ? 'warning' : 'success')
                    ->action(fn (AiModel $record) => $record->update(['is_enabled' => ! $record->is_enabled])),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAiModels::route('/'),
        ];
    }

    protected static function formatPricing(AiModel $record): string
    {
        $prompt = $record->pricing_prompt;
        $completion = $record->pricing_completion;

        if ($prompt === null || $completion === null || $prompt < 0 || $completion < 0) {
            return 'n/a';
        }

        if ($prompt == 0 && $completion == 0) {
            return 'free';
        }

        $promptStr = number_format((float) $prompt * 1_000_000, 2);
        $completionStr = number_format((float) $completion * 1_000_000, 2);

        return "\${$promptStr} / \${$completionStr}";
    }
}
