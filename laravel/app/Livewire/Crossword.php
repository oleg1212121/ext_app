<?php

namespace App\Livewire;

use Livewire\Component;

class Crossword extends Component
{

    public $height = 100;
    public $width = 100;
    public $minX = 100;
    public $maxX = 0;
    public $minY = 100;
    public $maxY = 0;
    public $grid = [];
    public $used = [];
    public $words = [
        "VEHEMENT",
        "DRUG",
        "CLUB",
        "CLIENT",
        "TORRENT",
        "PRESIDENT",
        "TRUMPET",
        "PRIORITY",
        "CLONE",
        "KEYBOARD",
        "REPRESENTATION",
        "AREA",
        "VEGETATION",
        "DEBOUNCE",
        "ABIDE",
        "GOD"
    ];

    private function crossword() {
    
       

        $firstWord = array_shift($this->words);

        usort($this->words, function ($a, $b) {
            return strlen($a) - strlen($b);
        });

        $y = 0;
        $x = 0;


        while ($y < $this->height) {
            $this->grid[$y] = [];
            $x = 0;
            while ($x < $this->width) {
                $this->grid[$y][$x] = ".";
                $x += 1;
            }
            $y += 1;
        }

        $y = intdiv($this->height, 2);
        $x = intdiv($this->width, 2) - 10;

        $this->used = [[true, $y, $x, $firstWord, 0]];

        $this->placeTheWord(true, $y, $x, $firstWord);
        
        while(count($this->words) > 0 ){
            $word = array_pop($this->words);
            $this->tryToPlace($word);
        }

        $arr = [];
        $i = 0;
        foreach($this->grid as $y => $row){
            if($y >= $this->minY && $y < $this->maxY ){
                $arr[] = [];
                foreach($row as $x => $cell){
                    if($x >= $this->minX && $x < $this->maxX ){
                        $arr[$i][] = $cell;
                    }
            
                }
                $i++;
            }
        }
        $this->grid = $arr;
        $words = [];
        foreach($this->used as $current){
            [$vector, $y, $x, $word, $times] = $current;
            $words[] = [$vector, $y-$this->minY, $x-$this->minX, $word];
        }
        $this->words = $words;
        // return view('crossword', ['grid' => $arr, 'words' => $words]);
        // dd($this->grid);
        // return $this->grid;
        // word_spell($words[2]);
    }


    private function tryToPlace($word) {
        
        $length = strlen($word) - 1;
        
        foreach ($this->used as $targetIndex => $target) {
            $left = intdiv($length, 2);
            $right = $left + 1;
            $index = $left;
            $direction = true;
            [$vector, $y, $x, $examiningWord, $usedTimes] = $target;
            if($usedTimes > 2) continue;
            for ($i = 0; $i < $length; $i++) {
                if($direction){    
                    $letter = $word[$left];
                    $index = $left;
                    $left--;
                } else {
                    $letter = $word[$right];
                    $index = $right;
                    $right++;
                }
                for ($j = 0; $j < strlen($examiningWord); $j++) {
                    $symbol = $examiningWord[$j];
                    if ($symbol === $letter) {
                        if ($vector) {
                            $yy = $y - $index;
                            $xx = $x + $j;                            
                        } else {
                            $yy = $y + $j;
                            $xx = $x - $index;
                        }

                        $possible = $this->checkPlacing(!$vector, $yy, $xx, $word);
                        if($possible){

                            $this->placeTheWord(!$vector, $yy, $xx, $word);
                            
                            $this->used[] = [!$vector, $yy, $xx, $word, 1];
                            $this->used[$targetIndex][4]++;


                            break(3);
                        } 
                      
                    }
                }
                $direction = !$direction;
            }

        }
    }

    private function checkPlacing($vector, $y, $x, $word) {
        $length = strlen($word);
        $possible = true;
        if ($y < 0 || $y >= $this->height || $x < 0 || $x >= $this->width) {
            $possible = false;
        }

        if ($possible) {
            if ($vector) {
                for ($i = 0; $i < $length; $i++) {
                    $cur = $word[$i];
                    $sym = $this->grid[$y][$x+$i];
                    if ($sym === "." || $cur === $sym) {
                        
                    } else {
                        $possible = false;
                        break;
                    }
                }
                if(
                    ($this->grid[$y][$x+$length] != "." && $this->grid[$y][$x+$length] != "*") || 
                    ($this->grid[$y][$x-1] != "." && $this->grid[$y][$x-1] != "*") 
                ){
                    $possible = false;
                    
                }
            } else {
                for ($i = 0; $i < $length; $i++) {
                    $cur = $word[$i];
                    $sym = $this->grid[$y+$i][$x];
                    if ($sym === "." || $cur === $sym) {
                        
                    } else {
                        $possible = false;
                        break;
                    }
                }
                if(
                    ($this->grid[$y+$length][$x] != "." && $this->grid[$y+$length][$x] != "*") ||
                    ($this->grid[$y-1][$x] != "." && $this->grid[$y-1][$x] != "*") 
                ){
                    $possible = false;
                    
                }
            } 
        }
       
        return $possible;
    }
    
    private function placeTheWord($vector, $y, $x, $word) {
        $word = "*" . $word . "*";
        $crosses = [];
        $this->minX = min($this->minX, $x);
        $this->minY = min($this->minY, $y);
        if($vector){
            $x--;
            for ($i = 0; $i < strlen($word); $i++) {
                if($this->grid[$y][$x] === $word[$i]){
                    $crosses[] = [$y, $x];
                }
                $this->grid[$y][$x] = $word[$i];
                $x++;
            }
            $this->maxX = max($this->maxX, $x-1);
        } else {
            $y--;
            for ($i = 0; $i < strlen($word); $i++) {
                if($this->grid[$y][$x] === $word[$i]){
                    $crosses[] = [$y, $x];
                }
                $this->grid[$y][$x] = $word[$i];
                $y++;
            }
            $this->maxY = max($this->maxY, $y-1);                           
        }
        foreach($crosses as [$y, $x]){  
            if(
                $this->grid[$y][$x-1] != "." && 
                $this->grid[$y][$x-1] != "*" && 
                $this->grid[$y-1][$x] != "." && 
                $this->grid[$y-1][$x] != "*" &&
                $this->grid[$y-1][$x-1] === "."
            ){
                $this->grid[$y-1][$x-1] = "*";
            }
            if(
                $this->grid[$y][$x+1] != "." && 
                $this->grid[$y][$x+1] != "*" && 
                $this->grid[$y-1][$x] != "." && 
                $this->grid[$y-1][$x] != "*" &&
                $this->grid[$y-1][$x+1] === "."
            ){
                $this->grid[$y-1][$x+1] = "*";
            }
            
            if(
                $this->grid[$y][$x+1] != "." && 
                $this->grid[$y][$x+1] != "*" && 
                $this->grid[$y+1][$x] != "." && 
                $this->grid[$y+1][$x] != "*" &&
                $this->grid[$y+1][$x+1] === "."
            ){
                $this->grid[$y+1][$x+1] = "*";
            }
            if(
                $this->grid[$y][$x-1] != "." && 
                $this->grid[$y][$x-1] != "*" && 
                $this->grid[$y+1][$x] != "." && 
                $this->grid[$y+1][$x] != "*" &&
                $this->grid[$y+1][$x-1] === "."
            ){
                $this->grid[$y+1][$x-1] = "*";
            }
        }
        return true;
    }

    public function render()
    {
        $this->crossword();
        return view('livewire.crossword');
    }
}
