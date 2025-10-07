<?php

namespace App\Classes;



class Parser
{
    protected const ALLOWED = [
        'a' => true,
        'b' => true,
        'c' => true,
        'd' => true,
        'e' => true,
        'f' => true,
        'g' => true,
        'h' => true,
        'i' => true,
        'j' => true,
        'k' => true,
        'l' => true,
        'm' => true,
        'n' => true,
        'o' => true,
        'p' => true,
        'q' => true,
        'r' => true,
        's' => true,
        't' => true,
        'u' => true,
        'v' => true,
        'w' => true,
        'x' => true,
        'y' => true,
        'z' => true,
        'A' => true,
        'B' => true,
        'C' => true,
        'D' => true,
        'E' => true,
        'F' => true,
        'G' => true,
        'H' => true,
        'I' => true,
        'J' => true,
        'K' => true,
        'L' => true,
        'M' => true,
        'N' => true,
        'O' => true,
        'P' => true,
        'Q' => true,
        'R' => true,
        'S' => true,
        'T' => true,
        'U' => true,
        'V' => true,
        'W' => true,
        'X' => true,
        'Y' => true,
        'Z' => true,
        "-" => true,
        "'" => true
    ];

    public static function parse($text)
    {
        $arr = explode(PHP_EOL, $text);
        $dictionary = [];
        foreach ($arr as $row) {
            $row = strtolower(trim($row));
            $chunks = explode(" ", $row);
            foreach($chunks as $chunk){
                $word = "";
                for($i=0;$i<strlen($chunk);$i++){
                    if(isset(static::ALLOWED[$chunk[$i]])){
                        $word .= $chunk[$i];
                    }
                }
                $word = trim($word, "-");
                $dictionary[$word] = ($dictionary[$word] ?? 0) + 1;
            }
        }
        return $dictionary;
    }

    public static function parseWord($str)
    {
        $str = strtolower(trim($str));
        $word = "";
        for($i=0;$i<strlen($str);$i++){
            if(isset(static::ALLOWED[$str[$i]])){
                $word .= $str[$i];
            }
        }
        $word = trim($word, "-");

        return $word;
    }
}
