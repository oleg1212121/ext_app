<?php

namespace App\Filament\Resources\BookTextFileResource\Pages;

use App\Classes\Parser;
use App\Filament\Resources\BookTextFileResource;
use Filament\Actions;
use Filament\Forms\Form;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Storage;

class CreateBookTextFile extends CreateRecord
{
    protected static string $resource = BookTextFileResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        
        $data['path'] = $data['attachment'];

        // $contents = Storage::disk('public')->get($attachment);
        // $arr = Parser::parse($contents);
        // dd($arr);
        return $data;
    }
}
