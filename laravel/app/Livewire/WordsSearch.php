<?php

namespace App\Livewire;

use Illuminate\Support\Facades\DB;
use Livewire\Component;

class WordsSearch extends Component
{
    public $search = '';

   

   

    public function render()
    {
        if($this->search){
            $words = DB::select("SELECT * FROM words WHERE word LIKE '%{$this->search}%';");
        } else {
            $words = DB::select("SELECT * FROM words;");
        }
        return view('livewire.words-search', [
            'words' => $words
        ]);
    }
}
