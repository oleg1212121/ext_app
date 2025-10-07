;
    document.addEventListener("alpine:init", () => {
        Alpine.data('main', () => ({
            isError: false,
            altPressed: false,
            vector: true, // horizontal
            crossword: {},
            // showDefinitions: false,
            currentTab: 0,
            showUnsolvedModal: false,
            definitions: [],
            obsolete: [],
            translations: [],
            forms: [],
            currentEmphasized: [],
            allowedKeys: [
                "." ,
                "a" ,
                'b' ,
                'c' ,
                'd' ,
                'e' ,
                'f' ,
                'g' ,
                'h' ,
                'i' ,
                'j' ,
                'k' ,
                'l' ,
                'm' ,
                'n' ,
                'o' ,
                'p' ,
                'q' ,
                'r' ,
                's' ,
                't' ,
                'u' ,
                'v' ,
                'w' ,
                'x' ,
                'y' ,
                'z' ,
                'A' ,
                'B' ,
                'C' ,
                'D' ,
                'E' ,
                'F' ,
                'G' ,
                'H' ,
                'I' ,
                'J' ,
                'K' ,
                'L' ,
                'M' ,
                'N' ,
                'O' ,
                'P' ,
                'Q' ,
                'R' ,
                'S' ,
                'T' ,
                'U' ,
                'V' ,
                'W' ,
                'X' ,
                'Y' ,
                'Z' ,
                "-" ,
                "'",
                " "
            ],
            vectors: [[1, 0], [0, 1], [-1, 0], [0, -1]],
            wordLevels: [
                {id: 0, name: 'Less 500 (A0)'},
                {id: 1, name: 'Less 1000 (A1)'},
                {id: 2, name: 'Less 3000 (A2)'},
                {id: 3, name: 'Less 5000 (B1)'},
                {id: 4, name: 'Less 8000 (B2)'},
                {id: 5, name: 'Less 10000 (C1)'},
                {id: 6, name: 'Less 20000 (C2)'},
                {id: 7, name: 'Native'}
            ],
            currentLevel: 7,
            textes: [],
            currentText: '',
            currentWord: '',
            solvedWords: [],
            async init() {
                this.getTextes()
            },
            async getTextes(){
                await fetch("/get-textes", {
                    method: "GET",
                    headers: {
                        "Content-type": "application/json;",
                    },
                })
                .then((response) => response.json())
                .then((response) => {

                    this.textes = response.data.textes;
                    // console.log(response.data.textes)
                    this.currentText = this.textes[0]['id']
                })
                .catch((error) => {
                    console.error("Error:", error);
                    this.isError = true;
                    this.spinner = false;
                });
            },
            async getCrossword(){
                this._refreshData()
                let data = {
                    'id': this.currentText,
                    'level': this.currentLevel
                }
                await fetch("/get-crossword", {
                    method: "POST",
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector("meta[name='csrf-token']").content,
                        "Content-type": "application/json;"
                    },
                    body: JSON.stringify(data)
                })
                .then((response) => response.json())
                .then((response) => {
                    if(response.data.crossword.used.length > 1){
                        this.crossword = response.data.crossword;
                        this.solvedWords = [];
                    }
                })
                .catch((error) => {
                    console.error("Error:", error);
                    this.isError = true;
                    this.spinner = false;
                });
            },
            _parseInt(y, x){
                y = parseInt(y)
                x = parseInt(x)
                return [y, x]
            },
            setAltBlock(){
                this.altPressed = true
            },
            unsetAltBlock(){
                this.altPressed = false
            },
            clickArrowCell(y, x){
                [y, x] = this._parseInt(y,x)
                let cell = this.crossword.newGrid[y][x]
                this.vector = cell.vector
                this.moveTargetCell(y, x, this.vector)
            },
            applySelectedCell(y, x){
                [y, x] = this._parseInt(y,x)
                let word = this._findWord(y, x)
                this.currentWord = word.value
                this.definitions = this.crossword.dictionary[word.value].definitions
                this.obsolete = this.crossword.dictionary[word.value].obsolete
                this.translations = this.crossword.dictionary[word.value].translations
                this.forms = this.crossword.dictionary[word.value].forms
                this.emphasizeWord(y, x)
                let nextElement = document.getElementById(y+'.'+x)
                if(nextElement){
                    nextElement.focus()
                }
            },
            clickSymbolCell(y, x){
                [y, x] = this._parseInt(y,x)
                this.applySelectedCell(y, x)
            },
            changeCell(event){
                console.log(event.key)
                if(this.altPressed){
                    return
                }
                let target = event.target
                let value = event.key
                let y = target.dataset.y
                let x = target.dataset.x
                let cell = this.crossword.newGrid[y][x]
                let direction = true
                if (event.key === 'ArrowLeft'){
                    this.moveTargetCell(y, x, 3)
                }
                if (event.key === 'ArrowRight'){
                    this.moveTargetCell(y, x, 1)
                }
                if (event.key === 'ArrowUp'){
                    this.moveTargetCell(y, x, 2)
                }
                if (event.key === 'ArrowDown'){
                    this.moveTargetCell(y, x, 0)
                }

                if(event.key === "Backspace" || event.key === "Delete"){


                    if(target.value != ''){
                        target.value = ''
                    } else {
                        if(!(cell.changeable ?? false)){
                            target.value = cell.value
                        }
                        if(this.crossword.newGrid[y][x]['type'] == 4){
                            this.changeFocus(y,x,false)
                        }
                    }
                }
                if(this.allowedKeys.includes(event.key)){

                    if(!cell.changeable){
                        target.value = cell.value
                    } else {
                        target.value = value
                        this._checkIfWordIsCorrect(y, x)
                    }
                    if(this.crossword.newGrid[y][x]['type'] == 4){
                        this.changeFocus(y,x,true)
                    }

                }
            },
            moveTargetCell(y, x, vector){
                [y, x] = this._parseInt(y,x)
                x += this.vectors[+vector][1]
                y += this.vectors[+vector][0]
                this.findPossibleCell(y, x, vector)
            },
            findPossibleCell(y, x, vector){
                [y, x] = this._parseInt(y,x)
                let count = 0
                while(this.crossword.newGrid[y][x]['type'] != 4 && count < 300){
                    count++
                    x += this.vectors[+vector][1]
                    y += this.vectors[+vector][0]
                    if(y < 0){
                        y = this.crossword.newGrid.length - 1
                    }
                    if(y >= this.crossword.newGrid.length){
                        y = 0
                    }
                    if(x < 0){
                        x = this.crossword.newGrid[0].length - 1
                    }
                    if(x >= this.crossword.newGrid[0].length){
                        x = 0
                    }
                }
                this.applySelectedCell(y, x)



            },
            changeFocus(y, x, direction=true){
                [y, x] = this._parseInt(y,x)
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
                    let nextElement = document.getElementById(y+'.'+x)
                    if(nextElement){
                        nextElement.focus()
                    }

                }

            },
            emphasizeWord(y, x){

                for(let i=0;i<this.currentEmphasized.length;i++){
                    this._paintWord(this.currentEmphasized[i], 'white')
                }
                this.currentEmphasized = []
                let word = this._findWord(y, x)

                if(!word) return;
                y = word.y
                x = word.x
                this.currentEmphasized.push(word)
                this._paintWord(word, 'blue')
                this._checkIfWordIsCorrect(y, x)
            },
            changeDefinitions(key) {
                this.definitions = this.crossword.dictionary[key].definitions
                this.obsolete = this.crossword.dictionary[key].obsolete
                this.translations = this.crossword.dictionary[key].translations
                this.forms = this.crossword.dictionary[key].forms
                this.showDefinitions = true
            },
            unsolvedList() {
                if (!this.crossword || !this.crossword.dictionary) return []
                const solved = new Set(this.solvedWords.map(w => ('' + w).toLowerCase()))
                return Object.keys(this.crossword.dictionary)
                    .filter(word => !solved.has(('' + word).toLowerCase()))
                    .map(word => ({ word, definitions: this.crossword.dictionary[word].definitions }))
            },
            _checkIfWordIsCorrect(y, x){
                let word = this._findWord(y, x)
                if(this.solvedWords.includes(word.value)) return;

                let isCorrect = true
                let len = word.value.length
                let symbol = '';
                let coordinats = []
                if(word.vector){
                    for(x=word.x;x<word.x+len;x++){
                        symbol = document.getElementById(y+"."+x)
                        if(symbol.value.toLowerCase() == word.value[x-word.x].toLowerCase()){
                            coordinats.push([y,x])
                        } else {
                            isCorrect = false
                            break
                        }
                    }
                } else {
                    for(y=word.y;y<word.y+word.value.length;y++){
                        symbol = document.getElementById(y+"."+x)
                        if(symbol.value.toLowerCase() == word.value[y-word.y].toLowerCase()){
                            coordinats.push([y,x])
                        } else {
                            isCorrect = false
                            break
                        }
                    }
                }
                if(isCorrect){
                    this._paintWord(word, 'green', false)
                    this._upvote(word.value)
                    this.solvedWords.push(word.value)
                }
            },
            _paintWord(word, color, changeable=true){
                let colors = ['green', 'white', 'grey', 'blue'];
                if(!colors.includes(color)){
                    return
                }
                // let symbol = null
                let x = word.x
                let y = word.y
                if(word.vector){
                    for(x=word.x;x<word.x+word.value.length;x++){
                        // document.getElementById(y+"."+x)
                        if(this.crossword.newGrid[y][x]['changeable']){
                            this.crossword.newGrid[y][x]['class'] = color
                            this.crossword.newGrid[y][x]['changeable'] = changeable

                            // document.getElementById(y+"."+x).classList.add(color)
                        }
                    }
                } else {
                    for(y=word.y;y<word.y+word.value.length;y++){
                        if(this.crossword.newGrid[y][x]['changeable']){
                            this.crossword.newGrid[y][x]['class'] = color
                            this.crossword.newGrid[y][x]['changeable'] = changeable

                            // document.getElementById(y+"."+x).classList.add(color)
                        }
                    }

                }
            },
            _findWord(y, x){
                [y, x] = this._parseInt(y, x)
                let cell = this.crossword.newGrid[y][x]
                let word1 = cell.words[0] ?? null
                let word2 = cell.words[1] ?? null
                let word = word1 ?? word2
                if(word1 && word2){
                    if(this.vector === word1.vector){
                        word = word1
                    } else {
                        word = word2
                    }
                } else {
                    this.vector = cell.vector
                }

                return word
            },
            async _upvote(word){
                let payload = {
                    word: word,
                    book: this.currentText
                }
                await fetch("/word/upvote", {
                    method: "POST",
                    headers: {
                        "Content-type": "application/json;",
                        'X-CSRF-TOKEN': document.querySelector("meta[name='csrf-token']").content
                    },
                    body: JSON.stringify(payload)

                })
                .then((response) => response)
                .then((response) => {
                    // this.textes = response.data.textes;

                })
                .catch((error) => {
                    console.error("Error:", error);
                    // this.isError = true;
                    // this.spinner = false;
                });
            },
            async _acknowledge(){
                let payload = {
                    word: this.currentWord
                }
                await fetch("/word/acknowledge", {
                    method: "POST",
                    headers: {
                        "Content-type": "application/json;",
                        'X-CSRF-TOKEN': document.querySelector("meta[name='csrf-token']").content
                    },
                    body: JSON.stringify(payload)
                })
                .then((response) => response)
                .then((response) => {
                    // this.textes = response.data.textes;

                })
                .catch((error) => {
                    console.error("Error:", error);
                    // this.isError = true;
                    // this.spinner = false;
                });
            },
            async _dismiss(){
                if(!this.currentWord) return;
                let payload = {
                    word: this.currentWord
                }
                await fetch("/word/dismiss", {
                    method: "POST",
                    headers: {
                        "Content-type": "application/json;",
                        'X-CSRF-TOKEN': document.querySelector("meta[name='csrf-token']").content
                    },
                    body: JSON.stringify(payload)
                })
                .then((response) => response)
                .then((response) => {
                    // optional: remove word from UI or give feedback
                })
                .catch((error) => {
                    console.error("Error:", error);
                });
            },
            async _askAI(){
                let word = this.currentWord || '';
                if(!word){
                    console.warn('No current word selected');
                    return;
                }
                let data = {
                    'word': word
                }
                try {
                    const res = await fetch('/word/ask-ai/', {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': document.querySelector("meta[name='csrf-token']").content,
                            "Content-type": "application/json;"
                        },
                        body: JSON.stringify(data)
                    })
                    .then((response) => response.json())
                    .then((response) => {
                        if(response.data.definitions){
                            console.log(response.data)
                            response.data.definitions.forEach(element => {
                                this.crossword.dictionary[word].definitions.push(element)
                            });
                            this.definitions = this.crossword.dictionary[word].definitions
                        }
                    })
                    .catch((error) => {
                        console.error("Error:", error);
                        this.isError = true;
                        this.spinner = false;
                    });
                    this.currentTab = 0;
                } catch (e) {
                    console.error('askGemini error:', e);
                }
            },
            _checkImage(){
                word = this.currentWord
                url = "https://www.google.com/search?q=" + word + "+meaning&sca_esv=c80bbe1f62332bda&rlz=1C1GCEU_ruBY1161BY1161&udm=2&biw=1920&bih=953&sxsrf=AE3TifO5QxLa7ypz2_SGzgyLkzPT2Re2TQ%3A1755789529135&ei=2TinaOL_B4nSwPAPnruG6AQ&ved=0ahUKEwii0LzZmZyPAxUJKRAIHZ6dAU0Q4dUDCBE&uact=5&oq=" + word + "+meaning&gs_lp=EgNpbWciDHdvcmQgbWVhbmluZzIFEAAYgAQyBRAAGIAEMgUQABiABDIFEAAYgAQyBRAAGIAEMgUQABiABDIFEAAYgAQyBRAAGIAEMgUQABiABDIFEAAYgARI7kBQAFifMXAJeACQAQCYAXCgAecKqgEEMTkuMbgBA8gBAPgBAZgCGqACkAqoAgrCAgoQIxgnGMkCGOoCwgIHEAAYgAQYCsICBBAAGB7CAgsQABiABBgBGBMYCsICBhAAGBMYHsICCBAAGBMYCBgewgIHECMYJxjJApgDBZIHAjI2oAfhWrIHAjE4uAf1CcIHBzAuMTUuMTHIB1E&sclient=img"
                window.open(url);
            },
            _refreshData(){
                this.crossword = {}
                this.currentWord = ''
                this.solvedWords = []
                this.isError = false
                this.altPressed = false
                this.vector = true
                this.currentTab = 0
                this.showUnsolvedModal = false
                this.definitions = []
                this.obsolete = []
                this.translations = []
                this.forms = []
                this.currentEmphasized = []
            }

        }))
    });
