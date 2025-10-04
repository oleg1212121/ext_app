<?php

namespace App\Filament\Resources\BookResource\Pages;

use App\Classes\Parser;
use App\Filament\Resources\BookResource;
use App\Models\Form;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CreateBook extends CreateRecord
{
    protected static string $resource = BookResource::class;

    protected function afterCreate(): void
    {
        $words = [];
        foreach($this->data['attachment'] as $attachment){
            $contents = Storage::disk('public')->get($attachment);
            $arr = Parser::parse($contents);
            foreach($arr as $word => $count){
                $words[$word] = ($words[$word] ?? 0) + $count;
            }
        }
        $forms = array_keys($words);
        $w = Form::whereIn('form', $forms)
        ->where('for_crossword', true)
        ->get();
        // dd($w);
        $sync = [];
        foreach($w as $word){
            if(!isset($sync[$word->word_id])){
                $sync[$word->word_id] = ['count' => 0];
            }
            $sync[$word->word_id]['count'] += $words[$word->form];
        }
        // dd($sync);
        $this->record->words()->sync($sync);
    }
}
