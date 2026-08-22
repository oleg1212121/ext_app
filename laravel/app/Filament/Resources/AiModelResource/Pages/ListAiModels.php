<?php

namespace App\Filament\Resources\AiModelResource\Pages;

use App\Filament\Resources\AiModelResource;
use App\Jobs\SyncAiModelsJob;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Cache;

class ListAiModels extends ListRecords
{
    protected static string $resource = AiModelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('sync')
                ->label('Sync models')
                ->icon('heroicon-o-arrow-path')
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Sync AI models')
                ->modalDescription('Fetch the latest model catalog from all configured AI providers in the background. Enabled models keep their status.')
                ->action(function (): void {
                    $lock = Cache::lock(SyncAiModelsJob::LOCK_KEY, SyncAiModelsJob::LOCK_TTL);

                    if (! $lock->get()) {
                        Notification::make()
                            ->title('Sync already in progress')
                            ->warning()
                            ->send();

                        return;
                    }

                    SyncAiModelsJob::dispatch($lock->owner());

                    Notification::make()
                        ->title('Sync queued')
                        ->body('The model catalog will be updated in the background.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
