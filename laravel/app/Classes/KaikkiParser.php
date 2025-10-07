<?php

namespace App\Classes;

use App\Classes\Crossword;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Word;


class KaikkiParser
{

    public static function getSpecificWords()
    {

        $words = DB::table("words2")->pluck('id', 'word')->toArray();

        $arrr = [];
        $count = 0;
        foreach ($words as $key => $v) {
            $words[strtolower($key)] = 1;
        }
        $path = getcwd();
        $file = $path . "/kaikki_english.jsonl";
        $file2 = $path . "/test.txt";

        $file = fopen($file, 'r');
        if (!$file) {
            echo 'NO FILE';
            return;
        }
        while (!feof($file)) {

            $cur = fgets($file);
            $line = json_decode($cur);


            if ($line->word ?? null) {
                $key = strtolower($line->word);
                if (isset($words[$key])) {

                    $word = [
                        'word' => null,
                        'forms' => [],
                        'translations' => [],
                        'senses' => [],
                        'sounds' => [],
                        'lang' => $line->lang ?? "*",
                        'pos' => $line->pos ?? "*",
                        'etymologies' => [],
                    ];
                    $word['word'] = $line->word ?? null;
                    if ($line->etymology_text ?? null) {
                        $word['etymologies'][] = $line->etymology_text;
                    }
                    foreach (($line->sounds ?? []) as $sound) {
                        if ($sound->ipa ?? null) {
                            $word['sounds'][] = $sound->ipa;
                        }
                    }

                    foreach (($line->forms ?? []) as $form) {
                        if ($form->form ?? null) {
                            $word['forms'][] = $form->form;
                        }
                    }


                    foreach (($line->synonyms ?? []) as $synonym) {
                        if ($synonym->word ?? null) {
                            $word['forms'][] = $synonym->word;
                        }
                    }


                    foreach (($line->senses ?? []) as $sense) {

                        foreach (($sense->glosses ?? []) as $gloss) {
                            $word['senses'][] = $gloss;
                        }

                        foreach (($sense->translations ?? []) as $translation) {
                            if (($translation->code ?? null) === 'ru') {
                                if ($translation->word ?? null) {
                                    $word['translations'][] = $translation->word;
                                }
                            }
                        }
                    }
                    foreach (($line->translations ?? []) as $translation) {
                        if (($translation->code ?? null) === 'ru') {
                            if ($translation->word ?? null) {
                                $word['translations'][] = $translation->word;
                            }
                        }
                    }

                    $arrr[] = $word;
                    $count++;
                    if ($count > 10000) {
                        $file3 = fopen($file2, "a");
                        foreach ($arrr as $key => $word) {
                            fwrite($file3, json_encode($word, true));
                            fwrite($file3, PHP_EOL);
                        }
                        fclose($file3);
                        $arrr = [];
                        $count = 0;
                    }
                }
            }
        }

        fclose($file);
    }

    public static function readWholeFile()
    {

        // $words = DB::table("words2")->pluck('id', 'word')->toArray();

        // $arr = [];
        // foreach ($words as $key => $v) {
        //     $arr[strtolower($key)] = 1;
        // }

        // $words = [];

        $path = getcwd();
        $file = $path . "/kai_ab.jsonl";

        $file = fopen($file, 'r');

        $linecount = 0;
        if (!$file) {
            echo 'NO FILE';
            return;
        }
        $forms = [];
        $etymologies = [];
        $sounds = [];
        $translations = [];
        $senses = [];
        while (!feof($file)) {
            $line = fgets($file);

            $line = json_decode($line);
            if (
                $line &&
                $line->word
                // && isset($arr[strtolower($line->word)])
                ) {
                $word = [
                    'pos' => $line->pos ?? "*",
                    'forms' => [],
                    'etymology_text' => '',
                    'word' => null,
                    'sounds' => [],
                    'translations' => [],
                    'senses' => [],
                ];
                $word['word'] = pg_escape_string($line->word ?? '');
                if (($line->etymology_text ?? null) && ($line->etymology_text != '')) {
                    $word['etymology_text'] = pg_escape_string($line->etymology_text);
                }
                foreach (($line->sounds ?? []) as $sound) {
                    if ($sound->ipa ?? null) {
                        $word['sounds'][] = $sound->ipa;
                    }
                    if ($sound->enpr ?? null) {
                        $word['sounds'][] = $sound->enpr;
                    }
                }

                foreach (($line->forms ?? []) as $form) {
                    if ($form->form ?? null) {
                        $word['forms'][] = $form->form;
                    }
                }

                foreach (($line->senses ?? []) as $sense) {
                    $rawGloss = $sense->raw_glosses ?? [];
                    if($rawGloss && count($rawGloss) > 0){
                        $gloss = implode(" ", $rawGloss);
                    } else {
                        $gloss = implode(" ", $sense->glosses ?? []);
                    }
                    if ($gloss != '') {
                        $word['senses'][] = $gloss;
                    }
                    foreach (($sense->translations ?? []) as $translation) {
                        if (($translation->code ?? null) === 'ru') {
                            if ($translation->word ?? null) {
                                $word['translations'][] = $translation->word;
                            }
                        }
                    }
                }
                foreach (($line->translations ?? []) as $translation) {
                    if (($translation->code ?? null) === 'ru') {
                        if ($translation->word ?? null) {
                            $word['translations'][] = $translation->word;
                        }
                    }
                }

                $word['forms'] = array_unique($word['forms']);
                $word['sounds'] = array_unique($word['sounds']);
                $word['translations'] = array_unique($word['translations']);
                $word['senses'] = array_unique($word['senses']);
                $words[] = $word['word'];
                if ($word['etymology_text'] != '') {
                    $etymologies[] = "('" . $word['pos'] . "', '" . $word['word'] . "', '" . $word['etymology_text'] . "')";
                }
                foreach ($word['forms'] as $form) {
                    $forms[] = "('" . $word['pos'] . "', '" . $word['word'] . "', '" . pg_escape_string($form) . "')";
                }
                foreach ($word['sounds'] as $sound) {
                    $sounds[] = "('" . $word['pos'] . "', '" . $word['word'] . "', '" . pg_escape_string($sound) . "')";
                }
                foreach ($word['translations'] as $translation) {
                    $translations[] = "('" . $word['pos'] . "', '" . $word['word'] . "', '" . pg_escape_string($translation) . "')";
                }
                foreach ($word['senses'] as $sense) {
                    $senses[] = "('" . $word['pos'] . "', '" . $word['word'] . "', '" . pg_escape_string($sense) . "')";
                }
            }
            $linecount++;

            if ($linecount > 5000) {

                $words = array_values(array_unique($words));
                $str = "('" . implode("'), ('", $words) . "')";
                $query = "INSERT INTO words (word) VALUES {$str} ON CONFLICT DO NOTHING;";
                // $query = pg_escape_string($query);
                DB::insert($query);
                $words = [];

                if ($etymologies) {
                    $str = implode(", ", $etymologies);
                    $query = "INSERT INTO etymologies (pos, word, etymology) VALUES {$str} ON CONFLICT DO NOTHING;";
                    // $query = pg_escape_string($query);
                    DB::insert($query);
                    $etymologies = [];
                }

                if ($forms) {
                    $str = implode(", ", $forms);
                    $query = "INSERT INTO forms (pos, word, form) VALUES {$str} ON CONFLICT DO NOTHING;";
                    // $query = pg_escape_string($query);
                    DB::insert($query);
                    $forms = [];
                }
                if ($sounds) {
                    $str = implode(", ", $sounds);
                    $query = "INSERT INTO transcriptions (pos, word, transcription) VALUES {$str} ON CONFLICT DO NOTHING;";
                    // $query = pg_escape_string($query);
                    DB::insert($query);
                    $sounds = [];
                }
                if ($translations) {
                    $str = implode(", ", $translations);
                    $query = "INSERT INTO translations (pos, word, translation) VALUES {$str} ON CONFLICT DO NOTHING;";
                    // $query = pg_escape_string($query);
                    DB::insert($query);
                    $translations = [];
                }
                if ($senses) {

                    $str = implode(", ", $senses);
                    $query = "INSERT INTO definitions (pos, word, definition) VALUES {$str} ON CONFLICT DO NOTHING;";
                    // $query = pg_escape_string($query);
                    DB::insert($query);
                    $senses = [];
                }

                $str = "";
                $query = "";
                $linecount = 0;
                // break;

            }
        }

        fclose($file);

        $words = array_values(array_unique($words));
        $str = "('" . implode("'), ('", $words) . "')";
        $query = "INSERT INTO words (word) VALUES {$str} ON CONFLICT DO NOTHING;";
        // $query = pg_escape_string($query);
        DB::insert($query);
        $words = [];

        if ($etymologies) {
            $str = implode(", ", $etymologies);
            $query = "INSERT INTO etymologies (pos, word, etymology) VALUES {$str} ON CONFLICT DO NOTHING;";
            // $query = pg_escape_string($query);
            DB::insert($query);
            $etymologies = [];
        }

        if ($forms) {
            $str = implode(", ", $forms);
            $query = "INSERT INTO forms (pos, word, form) VALUES {$str} ON CONFLICT DO NOTHING;";
            // $query = pg_escape_string($query);
            DB::insert($query);
            $forms = [];
        }
        if ($sounds) {
            $str = implode(", ", $sounds);
            $query = "INSERT INTO transcriptions (pos, word, transcription) VALUES {$str} ON CONFLICT DO NOTHING;";
            // $query = pg_escape_string($query);
            DB::insert($query);
            $sounds = [];
        }
        if ($translations) {
            $str = implode(", ", $translations);
            $query = "INSERT INTO translations (pos, word, translation) VALUES {$str} ON CONFLICT DO NOTHING;";
            // $query = pg_escape_string($query);
            DB::insert($query);
            $translations = [];
        }
        if ($senses) {

            $str = implode(", ", $senses);
            $query = "INSERT INTO definitions (pos, word, definition) VALUES {$str} ON CONFLICT DO NOTHING;";
            // $query = pg_escape_string($query);
            DB::insert($query);
            $senses = [];
        }
        return 2;
    }



    public static function insertWordsInfo()
    {


        $path = $path = getcwd();
        $file2 = $path . "/test.txt";

        $file = fopen($file2, 'r');
        if (!$file) {
            echo 'NO FILE';
            return;
        }

        $arr = [];
        $count = 0;
        while (!feof($file)) {

            $cur = trim(fgets($file));
            $line = json_decode($cur);
            if (!$line) {
                break;
            }
            $arr[] = "('" . $line->word . "', '" . json_encode($line, JSON_HEX_APOS) . "')";
            if ($count > 10000) {
                // break;
                $str =  implode(",", $arr);
                $query = "INSERT INTO infos (word, info) VALUES {$str} ON CONFLICT DO NOTHING;";

                DB::insert($query);
                // break;
                $count = 0;
                $arr = [];
            }
            $count++;
            // break;
        }

        fclose($file);
        $str =  implode(",", $arr);
        dd($count, $line, $str);
    }

    public static function test()
    {
        // dd(4);
        $path = getcwd();
        $file = $path . "/kai_ab.jsonl";

        $file = fopen($file, 'r');


        $linecount = 0;
        $words = [];
        if (!$file) {
            echo 'NO FILE';
            return;
        }
        $target = 1122;
        while (!feof($file)) {

            $cur = fgets($file);

            $line = json_decode($cur);
            if ($linecount > $target) {
                unset($line->lang);
                unset($line->lang_code);
                unset($line->head_templates);
                unset($line->hyphenation);
                unset($line->wikipedia);
                unset($line->meronyms);
                unset($line->synonyms);
                unset($line->hypernyms);
                unset($line->hyponyms);
                unset($line->holonyms);
                unset($line->related);
                unset($line->derived);
                unset($line->descendants);
                unset($line->etymology_number);
                unset($line->etymology_templates);

                dump($line);
            }
            $linecount++;
            if ($linecount > $target + 4) {
                break;
            }
        }
        fclose($file);
    }

    public static function lookForObsolete(){
        $path = getcwd();
        $file = $path . "/kaikki_english.jsonl";

        $file = fopen($file, 'r');


        $linecount = 0;
        $words = [];
        if (!$file) {
            echo 'NO FILE';
            return;
        }
        $target = 11;
        while (!feof($file)) {

            $cur = fgets($file);

            $line = json_decode($cur);
            if ($line && $line->word) {
                $word = $line->word ?? '';


                // $glosses = [];
                foreach (($line->senses ?? []) as $sense) {
                    foreach($sense->raw_glosses ?? [] as $gloss){
                        if(
                            str_contains($gloss, 'archaic') ||
                            str_contains($gloss, 'obsolete')||
                            str_contains($gloss, 'dated')
                        ){
                            $word = pg_escape_string($line->word ?? '');
                            $gloss = pg_escape_string($gloss);
                            $words[] = "{$word} ----- {$gloss}";
                            $linecount++;
                        }
                    }
                }

            }
            if($linecount > 10000){
                $path = getcwd();
                $file2 = $path . "/test22.txt";

                $file3 = fopen($file2, "a");
                foreach ($words as $key => $word) {
                    fwrite($file3, $word);
                    fwrite($file3, PHP_EOL);
                }
                fclose($file3);
                $words = [];
                $linecount = 0;

            }
        }
        fclose($file);
        dd($words);
    }

    public static function insertObsolete(){
        $path = getcwd();
        $file = $path . "/test22.txt";

        $file = fopen($file, 'r');


        $count = 0;
        $words = [];
        if (!$file) {
            echo 'NO FILE';
            return;
        }
        $target = 11;
        while (!feof($file)) {

            $cur = trim(fgets($file));
            if($cur && $count >= 50000){
                [$word, $definition] = explode(" ----- ", $cur);
                $definition = preg_replace("/^\([^)]+\)\s/", "", $definition);
                $definition = pg_escape_string($definition);
                echo "'".$definition."',";
                echo '<br>';
            }
            $count++;
            if($count > 60000){
                break;
            }
        }
        fclose($file);
    }





    public static function words_466k()
    {
        $path = $path = getcwd();
        $file2 = $path . "/words_alpha.txt";

        $file = fopen($file2, 'r');
        if (!$file) {
            echo 'NO FILE';
            return;
        }

        $arr = [];
        $count = 0;
        while (!feof($file)) {

            $cur = trim(fgets($file));
            if (!$cur) {
                break;
            }
// echo $cur;
// break;

            $arr[] = "('" . pg_escape_string($cur) . "')";
            if ($count > 20000) {
                $str =  implode(",", $arr);
                $query = "INSERT INTO words_466k (word) VALUES {$str} ON CONFLICT DO NOTHING;";

                DB::insert($query);
                $count = 0;
                $arr = [];
            }
            $count++;
        }

        fclose($file);
        // $str =  implode(",", $arr);
        // dd($count, $line, $str);
    }
}
