<?php

namespace App\Http\Controllers;

use App\Classes\AIModelResolver;
use App\Classes\Parser;
use App\Http\Requests\AiQuestionRequest;
use App\Http\Requests\DictionaryInteractionsSaveRequest;
use App\Http\Requests\DictionarySelectionSaveRequest;
use App\Http\Requests\GetTextsRequest;
use App\Http\Requests\TextRequest;
use App\Models\SavedPhrase;
use Exception;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class BilingualsController extends Controller
{
    public function __construct(
        protected AIModelResolver $modelResolver
    ) {}

    public function simulator()
    {
        $aiModels = $this->modelResolver->getGroupedModels();
        $textList = $this->getTextsArray();

        return view('simulator', [
            'aiModels' => $aiModels,
            'textList' => $textList,
        ]);
    }

    public function getTexts(GetTextsRequest $request)
    {
        $result = [
            'names' => [],
        ];
        $status = 200;
        try {
            $directory = public_path().'/texts/simulator';

            if (! is_dir($directory)) {
                throw new Exception('Directory not found');
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $name = $file->getPathname();
                    if (str_contains($name, ':Zone.Identifier')) {
                        continue;
                    }
                    $prefix = $directory.'/';
                    $name = str_replace($prefix, '', $name);
                    $result['names'][] = $name;
                }
            }
        } catch (Exception $e) {
            $status = 500;
            error_log('Files not found: '.$e->getMessage());
        }

        $data = [
            'data' => $result,
            'code' => $status,
        ];

        return response()->json(
            [
                'data' => $data,
            ],
            $status,
        );
    }

    private function getTextsArray(): array
    {
        $result = [];
        try {
            $directory = public_path('texts/simulator');

            if (! is_dir($directory)) {
                throw new Exception('Directory not found: '.$directory);
                // return [];
            }

            $files = glob($directory.'/*.txt');

            foreach ($files as $file) {
                $name = basename($file);
                if (str_contains($name, ':Zone.Identifier')) {
                    continue;
                }
                $result[$name] = $name;
            }
        } catch (Exception $e) {
            error_log('Files not found: '.$e->getMessage());
        }

        return $result;
    }

    public function text(TextRequest $request)
    {
        $status = 200;
        $result = [
            'rows' => [],
        ];
        $isRus = false;

        $filename = $request->get('filename', null);
        $filename = public_path('texts/simulator/'.$filename);

        if (file_exists($filename)) {
            $fd = fopen($filename, 'r');
            if ($fd) {
                $cur = ['', ''];

                while (($line = fgets($fd)) !== false) {
                    $line = trim($line);

                    if ($line === '') {
                        if ($isRus) {
                            $result['rows'][] = $cur;
                            $cur = ['', ''];
                        }
                        $isRus = ! $isRus;
                    } else {
                        if ($isRus) {
                            $cur[1] = $line;
                        } else {
                            $cur[0] = $line;
                        }
                    }
                }

                fclose($fd);
            } else {
                $status = 500;
                $result['error'] = 'Could not open file';
            }
        } else {
            $status = 404;
            $result['error'] = 'File not found';
        }

        $data = [
            'data' => $result,
            'code' => $status,
        ];

        return response()->json(
            [
                'data' => $data,
            ],
            $status,
        );
    }

    public function askAi(AiQuestionRequest $request)
    {
        $status = 200;
        $prompt = $request->validated('data');

        $instruction = $request->validated('question');
        $modelString = $request->validated('model');

        try {
            $answer = $this->modelResolver->ask($modelString, $instruction, $prompt);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'data' => [
                    'answer' => 'Error: Invalid model selection',
                    'code' => 400,
                ],
            ], 400);
        }

        $data = [
            'answer' => $answer,
            'code' => $status,
        ];

        return response()->json(
            [
                'data' => $data,
            ],
            $status
        );
    }

    public function selectionSave(DictionarySelectionSaveRequest $request)
    {

        $status = 200;
        $selection = $request->get('selection', null);
        $phrase = new SavedPhrase([
            'phrase' => $selection,
        ]);
        $phrase->save();

        return response()->json(
            [
                'data' => [],
            ],
            $status
        );
    }

    public function interactionsSave(DictionaryInteractionsSaveRequest $request)
    {

        $status = 200;
        $words = $request->get('words', []);

        if (count($words) > 0) {

            $caseStatements = [];
            $keys = [];
            foreach ($words as $key => $value) {
                $key = Parser::parseWord($key);
                $key = pg_escape_string($key);
                $keys[] = $key;
                $caseStatements[] = "WHEN '".$key."' THEN ".(int) $value;
            }
            $caseSql = implode(' ', $caseStatements);
            $keys = implode("','", $keys);
            $query = "
                UPDATE words
                SET knowledge = GREATEST(COALESCE(words.knowledge, 0) + ff.addition, 0)
                FROM (
                    SELECT DISTINCT f.word,
                        CASE f.form
                        {$caseSql}
                        ELSE 0
                        END as addition
                    FROM forms f
                    WHERE f.form IN (
                        '{$keys}'
                    )
                ) AS ff
                WHERE words.word = ff.word;";
        }
        DB::insert($query);

        return response()->json(
            [
                'data' => [],
            ],
            $status
        );
    }
}
