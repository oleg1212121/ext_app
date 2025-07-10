<?php 

namespace App\Classes;


class Crossword
{
    public $height = 100;
    public $width = 100;
    public $minX = 100;
    public $maxX = 0;
    public $minY = 100;
    public $maxY = 0;
    public $grid = [];
    public $used = [];
    public $newGrid = [];
    public $words = [];
    public $dictionary = [];
    public function __construct($words)
    {
        foreach($words as $word){
            if(strlen($word->word) < 2) continue;
            $this->words[] = $word->word;
            $this->dictionary[$word->word] = [];
            $arr = [];
            foreach($word->definitions as $definition){ 
                $this->dictionary[$word->word][] = "(". $definition->pos .") " . $definition->definition;
            }
            foreach($word->translations as $translation){
                $this->dictionary[$word->word][] = "(". $translation->pos .") " . $translation->translation;              
            }
            if(count($this->dictionary[$word->word]) == 0){
                array_pop($this->words);
                unset($this->dictionary[$word->word]);
            }
        }
        
    }


    public function crossword() {
        

        usort($this->words, function ($a, $b) {
            return strlen($a) - strlen($b);
        });
        $firstWord = array_pop($this->words);
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
                        if($cell === "."){
                            $cell = "";
                        }
                        $arr[$i][] = $cell;
                    }
            
                }
                $i++;
            }
        }
        $this->grid = $arr;
        $words = [];
        
        $this->maxY++;
        $this->maxX++;
        for($y=$this->minY;$y<$this->maxY;$y++){
            $this->newGrid[] = [];       
            for($x=$this->minX;$x<$this->maxX;$x++){
                $this->newGrid[$y-$this->minY][] = [
                    'vector' => false, 
                    'y' => $y-$this->minY, 
                    'x' => $x-$this->minX,
                    'value' => '',
                    'type' => 1,
                    'words' => [],
                    
                    'class' => 'white'
                ];
            }
        }
        foreach($this->used as $key => $current){
            [$vector, $y, $x, $word, $times] = $current;
            $words[] = [
                'id' => $key + 1,
                'vector' => !$vector, 
                'y' => $y-$this->minY, 
                'x' => $x-$this->minX,
                'value' => $word,
                'type' => 1,
                'words' => [],
            ];
        }
        $this->words = $words;

        foreach($this->words as $current){
            if(!$current['vector']){
                $this->newGrid[$current['y']][$current['x']-1]['type'] = 2;
                $this->newGrid[$current['y']][$current['x']-1]['value'] = $current['value'];
                $this->newGrid[$current['y']][$current['x']-1]['vector'] = true;
                for($i=0;$i<strlen($current['value']);$i++){
                    $this->newGrid[$current['y']][$current['x']+$i]['value'] = $current['value'][$i];
                    
                    $this->newGrid[$current['y']][$current['x']+$i]['words'][] = $current;
                    $this->newGrid[$current['y']][$current['x']+$i]['type'] = 4;
                    $this->newGrid[$current['y']][$current['x']+$i]['vector'] = true;
                }
            } else {
                $this->newGrid[$current['y']-1][$current['x']]['type'] = 3;
                
                $this->newGrid[$current['y']-1][$current['x']]['value'] = $current['value'];
                $this->newGrid[$current['y']-1][$current['x']]['vector'] = false;
                for($i=0;$i<strlen($current['value']);$i++){
                    $this->newGrid[$current['y']+$i][$current['x']]['value'] = $current['value'][$i];
                    $this->newGrid[$current['y']+$i][$current['x']]['type'] = 4;
                    
                    $this->newGrid[$current['y']+$i][$current['x']]['words'][] = $current;
                    $this->newGrid[$current['y']+$i][$current['x']]['vector'] = false;
                }
            }
        }
        // return $this;
        // return view('crossword', ['grid' => $arr, 'words' => $words]);
        // dd($this->grid);
        // return $this->grid;
        // word_spell($words[2]);
    }



    private function setWordToGrid($word, $i){

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
                    ($this->grid[$y][$x-1] != "." ) 
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
                    ($this->grid[$y-1][$x] != "." ) 
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
        $this->minX = min($this->minX, $x-1);
        $this->minY = min($this->minY, $y-1);
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
}