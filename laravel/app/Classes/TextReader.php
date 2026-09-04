<?php

namespace App\Classes;

use App\Models\EnEntity;
use App\Models\RuEntity;

class TextReader
{
    public $basePath = '';

    public $table = '';

    public function __construct()
    {
        $this->setBasePath(public_path('texts/simulator'));
    }

    public function setBasePath(string $basePath): TextReader
    {
        $this->basePath = $basePath;

        return $this;
    }

    public function readCombinedFile($fileName)
    {
        $handle = fopen($this->basePath.'/'.$fileName, 'r');
        if ($handle) {
            $ruEntityName = '';
            $enEntityName = '';
            $ruEntity = RuEntity::where('name', 'like%', $ruEntityName)->first();
            $enEntity = EnEntity::where('name', 'like%', $enEntityName)->first();
            $rowNumber = 0;
            $en = [];
            $ru = [];
            while (($line = fgets($handle)) !== false) {
                if ($rowNumber === 0) {
                    // en
                    $en[] = $line;
                } elseif ($rowNumber === 1) {
                    continue;
                } else {
                    // ru
                    $ru[] = $line;

                    $rowNumber = -1;
                }
                $rowNumber++;
            }
            fclose($handle);
        } else {
            // Error opening file
        }

    }
}
