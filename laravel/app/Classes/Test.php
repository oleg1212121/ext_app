<?php 

namespace App\Classes;


class Test
{
    
    public function pest(){
        echo 'gggg';
    }

    public static function test(){
        $file = "D:\python-projects\book-maker\kaikki_english.jsonl";
        $file = fopen($file, 'r');

        if (!$file) {
            echo 'NO FILE';
            return; // die() is a bad practice, better to use return
        }    
        // while (($line = fgets($file)) !== false) {
        //     yield $line;
        // }
        $line = fgets($file);
        var_dump($line);
        fclose($file);
        echo 'wtf';
        echo 'hellaaaa';
    }
}
