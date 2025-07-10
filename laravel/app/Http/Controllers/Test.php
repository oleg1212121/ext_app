<?php

namespace App\Http\Controllers;

use App\Classes\Crossword;
use App\Classes\KaikkiParser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Word;

class Test extends Controller
{
    public function test()
    {
        KaikkiParser::test();


    }

    
    public function reader()
    {
        $folder = public_path("textes");
        $filename1 = '/the_book_thief_0_en.txt';
        $filename2 = '/the_book_thief_0_ru.txt';
        $file1 = fopen($folder . $filename1, "r");
        $rows = [];
        if ($file1) {
            while (($buffer = fgets($file1, 4096)) !== false) {
                $rows[] = [$buffer];
            }

            if (!feof($file1)) {
                echo "Error: unexpected fgets() fail\n";
            }

            fclose($file1);
        }

        $file2 = fopen($folder . $filename2, "r");
        $index = 0;
        if ($file2) {
            while (($buffer = fgets($file2, 4096)) !== false) {
                $rows[$index][] = $buffer;
                $index++;
            }

            if (!feof($file2)) {
                echo "Error: unexpected fgets() fail\n";
            }

            fclose($file2);
        }
        return view('components.reader', [
            'rows' => $rows
        ]);
    }

    public function crossword()
    {
        return view("crossword");
    }

    public function getCrossword()
    {
        $words = Word::where('is_full', true)
        ->with(['definitions', 'translations'])
        ->inRandomOrder()
        ->limit(20)
        ->get();

        $crossword = new Crossword($words);
        $crossword->crossword();
        return response()->json(
            [
                'data' => [
                    'crossword' => $crossword
                ]
            ],
            200,
            [
                'Content-Type: application/json;'
            ]
        );
    }
}
