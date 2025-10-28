<?php

namespace App\Http\Controllers;

use App\Classes\Crossword;
use App\Classes\Crossword2;
use App\Classes\Gemini;
use App\Classes\KaikkiParser;
use App\Http\Requests\GetCrosswordRequest;
use App\Http\Requests\WordAcknowledgeRequest;
use App\Http\Requests\WordAskAiRequest;
use App\Http\Requests\WordDismissRequest;
use App\Http\Requests\WordUpvoteRequest;
use App\Models\Book;
use App\Models\BookWord;
use App\Models\Definition;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Word;

class Test extends Controller
{
    public function test()
    {
        $rows = $this->buildBilingualText();
        dd($rows);
    }

    public function reader()
    {
        $folder = public_path("textes");
        $title = "the_book_thief_4";
        $filename1 = "/{$title}_en.txt";
        $filename2 = "/{$title}_ru.txt";
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

    public function buildBilingualText(){

        $folder = public_path("textes");
        $title = "the_book_thief_4";
        $filename1 = "/{$title}_en.txt";
        $filename2 = "/{$title}_ru.txt";
        $filename3 = "/simulator/{$title}.txt";
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

        $file3 = fopen($folder . $filename3, "w");
        foreach($rows as [$l,$r]){
            fwrite($file3, $l);
            fwrite($file3, "\r\n");
            fwrite($file3, $r);
            fwrite($file3, "\r\n");
        }
        fclose($file3);
        return view('components.reader', [
            'rows' => $rows
        ]);

    }

    public function crossword()
    {
        return view("crossword");
    }

    public function getCrossword(GetCrosswordRequest $request)
    {
        $levels = [
            'less_100',
            'less_500',
            'less_1000',
            'less_3000',
            'less_5000',
            'less_10000',
            'less_20000',
            'less_1000000',
        ];

        $id = $request->get('id', -1);
        $level = $levels[$request->get('level', 0)] ?? 'less_100';
        $words = Word::select('words.*')
        ->join('book_word', 'book_word.word_id', '=', 'words.id')
        // ->where('words.has_definitions', true)
        // ->where('words.is_full', true)
        ->where('words.for_crossword', true)
        ->where('words.is_known', false)
        ->where("words.{$level}", true)
        ->where('words.knowledge', '<', 60)
        ->where('book_word.book_id', $id)
        ->where('book_word.is_solved', false)
        // ->whereHas('modernDefinitions')
        ->with([
            'translations',
            // 'modernDefinitions',
            'definitions',
            'forms'
        ])
        ->orderBy('updated_at')
        ->limit(30)
        ->get();
            // dd($words);
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

    public function getTextes()
    {
        $textes = Book::select('id', 'name')
        ->limit(100)
        ->get();

        return response()->json(
            [
                'data' => [
                    'textes' => $textes
                ]
            ],
            200,
            [
                'Content-Type: application/json;'
            ]
        );
    }

    public function upvote(WordUpvoteRequest $request){
        $word = $request->get('word', '');
        $book = $request->get('book', '');

        $word = Word::where('word', '=', $word)->firstOrFail();
        $book = Book::where('id', $book)->firstOrFail();

        $word->knowledge++;
        $word->save();

        $bookWord = BookWord::where('word_id', $word->id)
        ->where('book_id', $book->id)->firstOrFail();
        $bookWord->is_solved = true;
        $bookWord->save();

        return response("", 200);
    }

    public function acknowledge(WordAcknowledgeRequest $request){
        $word = $request->get('word', '');
        $word = Word::where('word', '=', $word)->firstOrFail();

        $word->knowledge = 60;
        $word->save();

        return response("", 200);
    }

    public function dismiss(WordDismissRequest $request){
        $word = $request->get('word', '');
        $word = Word::where('word', '=', $word)->firstOrFail();

        $word->for_crossword = false;
        $word->save();

        return response("", 200);
    }
    public function askAI(WordAskAiRequest $request)
    {
        $word = $request->get('word', '');
        $arr = [];
        $gemini = new Gemini();
        $res = $gemini->ask($word);
        if($res){
            $arr = explode(PHP_EOL, $res);
            $data = [];
            foreach($arr as $v){
                $data[] = [
                    'pos' => 'noun',
                    'word' => $word,
                    'definition' => $v,
                    'is_obsolete' => false
                ];
            }
            // Definition::insert($data);
        }
        return response()->json(
            [
                'data' => [
                    'definitions' => $arr
                ]
            ],
            200,
            [
                'Content-Type: application/json;'
            ]
        );
    }

}
