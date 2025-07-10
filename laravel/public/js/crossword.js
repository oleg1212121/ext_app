;
    document.addEventListener("alpine:init", () => {
        Alpine.data('main', () => ({
            isError: false,
            vector: false,
            crossword: {},
            showDefinitions: false,
            definitions: [],
            currentEmphasized: [],
            async init() {
                await fetch("/get-crossword", {
                    method: "GET",
                    headers: {
                        "Content-type": "application/json;",
                    },
                })
                .then((response) => response.json())
                .then((response) => {
                    console.log(response);
                    this.crossword = response.data.crossword;
                    // console.log(this.crossword);
                    // if (response.code == 200) {
                    //   console.log('success');
                    //   this.crossword = response.data.crossword;
                    //   console.log(this.crossword);
                    // } else {
                    //   this.isError = true;
                    //   console.log("error");
                    // }

                    // this.spinner = false;
                })
                .catch((error) => {
                    console.error("Error:", error);
                    this.isError = true;
                    this.spinner = false;
                });

                // for(word in this.crossword.words){

                // }
            },
            clickArrowCell(cell){
                this.definitions = this.crossword.dictionary[cell.value]
                this.vector = cell.vector
                this.emphasizeWord(cell.value)
                this.changeFocus(cell.y, cell.x, true)
            },
            
            clickSymbolCell(cell){
                // console.log(this.vector)
                word1 = cell.words[0] ?? null
                word2 = cell.words[1] ?? null
                if(word1 && word2){
                    if(this.vector == word1.vector){
                        this.definitions = this.crossword.dictionary[word1.value]
                        this.emphasizeWord(word1.value)
                    } else {
                        this.definitions = this.crossword.dictionary[word2.value]
                        this.emphasizeWord(word2.value)
                    }
                } else {
                    this.definitions = this.crossword.dictionary[word1.value]
                    
                    this.vector = cell.vector
                    this.emphasizeWord(word1.value)
                }
                
            },
            changeCell(event){
                // console.log(event.key)
                // console.log(event.target.attributes.id)
                target = event.target
                value = event.key
                y = target.dataset.y
                x = target.dataset.x
                // event.target.value = ''
                direction = true
                if(event.key === "Backspace"){
                    // console.log('backspace')
                    direction = false
                    target.value = ''
                } else {
                    if(event.key.match(/[a-zA-Z]/i)){
                        
                        
                        target.value = value
                        
                    } else {
                        
                        return
                    }
                
                }
                if(this.crossword.newGrid[y][x]['type'] == 4){
                    this.changeFocus(y,x,direction)
                } else {
                    console.log('no')
                    
                }
                
            },
            changeFocus(y, x, direction=true){
                if(this.vector){
                    if(direction){
                        x++
                    } else {
                        x--
                    }
                } else {
                    if(direction){
                        y++
                    } else {
                        y--
                    }
                }
                if(this.crossword.newGrid[y][x]['type'] == 4){
                    nextElement = document.getElementById(y+'.'+x)
                    if(nextElement){
                        nextElement.focus()
                    }
                }
            },
            emphasizeWord(key){
                for(i=0;i<this.currentEmphasized.length;i++){
                    [y,x] = this.currentEmphasized[i]
                    this.crossword.newGrid[y][x]['class'] = 'white'
                }
                this.currentEmphasized = []
                word = null
                for(k=0; k < this.crossword.words.length;k++){
                    w = this.crossword.words[k]
                    
                    if(key == w['value']){
                        word = w
                        break
                    }
                }
                y = word.y
                x = word.x
                if(word.vector){
                    for(y=word.y;y<word.y+word.value.length;y++){
                        this.currentEmphasized.push([y,x])
                        this.crossword.newGrid[y][x]['class'] = 'green'
                    }
                } else {

                    for(x=word.x;x<word.x+word.value.length;x++){
                        this.currentEmphasized.push([y,x])
                        this.crossword.newGrid[y][x]['class'] = 'green'
                    }
                }
            },
            changeDefinitions(key) {
                this.definitions = this.crossword.dictionary[key]
                this.showDefinitions = true
            }
        }))
    });