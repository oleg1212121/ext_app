;
      document.addEventListener("alpine:init", () => {
        Alpine.data("main", () => ({
          // question: "I will give you two texts: the original in Russian and my version of the translation. Your tasks are: 1. Assess how accurately I convey the overall meaning. 2. Point out my grammatical errors and ways for improvement. 3. The response should be in English.",
          question:
            "Compare Russian original vs. my translation. Tasks: 1. Assess meaning accuracy. 2. Fix grammar/improve.",
          text: "",
          filename: "",
          searchPhrase: "",
          showFilename: false,
          showWorkplace: true,
          showQuestion: false,
          spinner: false,
          leftColumn: true,
          contextModal: false,
          rightColumn: false,
          middleColumn: true,
          isError: false,
          phonetics: "",
          translations: "",
          examples: "",
          meansLike: "",
          etymology: "",
          definitions: "",
          origin: "",
          word: "",
          isJustDictionary: "",
          mnemonic: "",
          aiAnswer: "",
          fontSize: 30,
          middleColumnWidth: 620,
          rightColumnWidth: 400,
          selectedChat: "gemini-2.5-flash-lite",
          chats: [
            "gemini-2.5-flash",
            "gemini-2.5-flash-lite",
            "gemini-2.5-flash-preview-09-2025",
            "gemini-2.5-flash-lite-preview-09-2025",
          ],
          rows: [
            ["english part", "russian part"],
            ["english part", "russian part"],
            ["english part", "russian part"],
          ],
          textsList: [" "],
          interractedWords: {},
          interractedSentences: [],
        //   object: { field: {} },
          pep: false,
          init() {
            fetch("/get-textes", {
              method: "POST",
              body: JSON.stringify({}),
              headers: {
                'X-CSRF-TOKEN': document.querySelector("meta[name='csrf-token']").content,
                "Content-type": "application/json;",
              },
            })
              .then((response) => response.json())
              .then((response) => {
                if (response.data.code == 200) {
                  console.log("success");
                  this.textsList = response.data.data.names;
                  this.filename = this.textsList[0]
                } else {
                  this.isError = true;
                  console.log("error");
                }

                this.spinner = false;
              })
              .catch((error) => {
                console.error("Error:", error);
                this.isError = true;
                this.spinner = false;
              });
          },
          copyInfo(item) {
            let result = item[1] + "\n" + this.text;
            navigator.clipboard.writeText(result);
          },
          contextModalShow() {
            let selection = window.getSelection().toString().trim();
            if (!this.contextModal) {
              if (selection || this.word) {
                this.contextModal = true;
              }
            } else {
              if (selection && selection != this.word) {
                this.contextModal = true;
              } else {
                this.contextModal = false;
              }
            }
            // if(selection || this.word){
            //   this.contextModal = !this.contextModal
            // }
            if (selection && this.word != selection) {
              this.word = selection;
              this.searching();
            }
          },
          formatting(item) {
            return "<span style='background-color: red;'>" + item + "</span>";
          },
          openWorkplace() {
            this.text = "";
            this.showWorkplace = true;
            let tArea = document.getElementById("workplace_textarea");
            tArea.value = "";
            console.log("hello there");
            tArea.focus();
          },
          changingWidth(direct, varName) {
            if (direct == "+") {
              if (varName == "middleColumnWidth") {
                this.middleColumnWidth += 4;
              } else {
                this.rightColumnWidth += 4;
              }
            } else {
              if (varName == "middleColumnWidth") {
                this.middleColumnWidth -= 4;
              } else {
                this.rightColumnWidth -= 4;
              }
            }
          },
          changeFontSize(direct) {
            if (direct == "+") {
              this.fontSize += 2;
            } else {
              this.fontSize -= 2;
            }
          },
          ask(item) {
            let rus = item[1].trim().toString().replace("*", "");
            let eng = this.text.trim().toString().replace("*", "");
            console.log(rus, eng);
            if (rus && eng) {
              console.log("asking");
              let result = item[1] + "\n" + this.text;
              this.spinner = true;
              data = {
                data: result,
                question: this.question,
                model: this.selectedChat,
              };
              fetch("/ai/question", {
                method: "POST",
                body: JSON.stringify(data),
                headers: {
                    'X-CSRF-TOKEN': document.querySelector("meta[name='csrf-token']").content,
                    "Content-type": "application/json;",
                },
              })
                .then((response) => response.json())
                .then((response) => {
                  if (response.data.code == 200) {
                    console.log("success");
                    this.aiAnswer = response.data.answer;

                    this.isError = false;
                  } else {
                    this.isError = true;
                    console.log("error---");
                  }

                  this.spinner = false;
                })
                .catch((error) => {
                  console.error("Error:", error);
                  this.isError = true;
                  this.spinner = false;
                });
            }
          },
          searchFile() {
            this.spinner = true;
            this.rows = [];
            data = {
              filename: this.filename,
            };
            // console.log(data)
            fetch("/text", {
              method: "POST",
              body: JSON.stringify(data),
              headers: {
                'X-CSRF-TOKEN': document.querySelector("meta[name='csrf-token']").content,
                "Content-type": "application/json;",
              },
            })
              .then((response) => response.json())
              .then((response) => {
                console.log(response)
                if (response.data.code == 200) {
                  console.log("success");
                  this.rows = response.data.data.rows;
                  let arr = this.filename.split("\\");
                  document.getElementById("title").innerHTML = arr[arr.length - 1];
                  this.isError = false;
                } else {
                  this.isError = true;
                  console.log("error---");
                }

                this.spinner = false;
              })
              .catch((error) => {
                console.error("Error:", error);
                this.isError = true;
                this.spinner = false;
              });
          },
          mnemonicSearch() {
            this.spinner = true;
            this.isError = false;
            data = {
              word: this.word,
            };
            fetch("/word/mnemonic", {
              method: "POST",
              body: JSON.stringify(data),
              headers: {
                "Content-type": "application/json;",
              },
            })
              .then((response) => response.json())
              .then((response) => {
                if (response.code == 200) {
                  this.mnemonic = response.data.mnemonic;
                  this.isError = false;
                  // this.aiAnswer = response.data.mnemonic;
                } else {
                  this.isError = true;
                  console.log("error");
                }

                this.spinner = false;
              })
              .catch((error) => {
                console.error("Error:", error);
                this.isError = true;
                this.spinner = false;
              });
          },
          searching() {
            this.spinner = true;
            this.isError = false;
            this.refreshData();
            data = {
              word: this.word,
            };
            fetch("/word/search", {
              method: "POST",
              body: JSON.stringify(data),
              headers: {
                "Content-type": "application/json;",
              },
            })
              .then((response) => response.json())
              .then((response) => {
                if (response.code == 200) {
                  // console.log(response);
                  this.phonetics = response.data.phonetics;
                  this.examples = response.data.examples;
                  this.translations = response.data.translations;
                  this.definitions = response.data.definitions;
                  this.isError = false;
                } else {
                  this.isError = true;
                  console.log("error");
                }

                this.spinner = false;
              })
              .catch((error) => {
                console.error("Error:", error);
                this.isError = true;
                this.spinner = false;
              });
          },
          createAnki() {
            if (!this.mnemonic) {
              this.isError = true;
            } else {
              this.spinner = true;
              this.isError = false;
              let data = {
                word: this.word,
                translations: this.translations,
                phonetics: this.phonetics,
                examples: this.examples,
                mnemonic: this.mnemonic,
                definitions: this.definitions,
              };
              fetch("/anki/create", {
                method: "POST",
                body: JSON.stringify(data),
                headers: {
                  "Content-type": "application/json;",
                },
              })
                .then((response) => response.json())
                .then((response) => {
                  this.spinner = false;
                  if (response.code == 200) {
                    this.isError = false;
                    this.refreshData();
                    this.word = "";
                  } else {
                    this.isError = true;
                  }
                })
                .catch((error) => {
                  console.error("Error:", error);
                  this.isError = true;
                  this.spinner = false;
                });
            }
          },
          refreshData() {
            this.phonetics = "";
            this.translations = "";
            this.examples = "";
            this.definitions = "";
            this.mnemonic = "";
          },
          triggerAskQuestion() {
            let ell = document.querySelectorAll(".check_ru:checked");
            if (ell.length > 0) {
              console.log("shortcut");
              let parent = ell[ell.length - 1].closest('.row');
              let text = parent.querySelector(".rus.content").textContent;
              // button.click();
              // this.text
              this.ask(["", text]);
            }
            console.log("aaaaaaasdasdasd");
          },
          showSelectionModal(){

            const selection = window.getSelection();
            if (selection.rangeCount === 0) {
                return null;
            }
            this.pep = true;
            const range = selection.getRangeAt(0);
            const rect = range.getBoundingClientRect();
            const modal = document.getElementById('selection-modal');
            modal.style.left = (rect.left - 0) + 'px'
            modal.style.top = (rect.top - 70) + 'px'
            this.word = selection.toString()
        //     console.log({
        //         x: rect.left + window.scrollX,
        //         y: rect.top + window.scrollY,
        //         width: rect.width,
        //         height: rect.height,
        //         text: selection.toString()
        //   });
          },
          saveSelection(){
            this.pep = false
            if (!this.word) {
                return null;
            }
            // this.spinner = true;
            // this.rows = [];
            data = {
              selection: this.word,
            };
            // console.log(data)
            fetch("/dictionary/selection/save", {
              method: "POST",
              body: JSON.stringify(data),
              headers: {
                'X-CSRF-TOKEN': document.querySelector("meta[name='csrf-token']").content,
                "Content-type": "application/json;",
              },
            })
              .then((response) => response.json())
              .then((response) => {

              })
              .catch((error) => {
                console.error("Error:", error);

              });

          },
          memorizeHighlight(){
            const selection = window.getSelection();
            if (selection.rangeCount === 0) {
                return null;
            }
            this.__splitAndMemorize(selection.toString(), -2)
            // console.log(this.interractedWords);

            this.storeMemorizedWords()
          },
          memorizeSentence(index){
            if(index in this.interractedSentences){
                return null
            }
            this.interractedSentences.push(index)
            let sentence = this.rows[index][0]
            // console.log(sentence)
            this.__splitAndMemorize(sentence, 1)
            this.storeMemorizedWords()
          },
          storeMemorizedWords(){

            // console.log(this.interractedWords)
            if (Object.keys(this.interractedWords).length < 20) {
                return null;
            }
            // console.log('omg')
            // console.log(this.interractedWords)
            let data = {
              words: this.interractedWords,
            };
            let body = JSON.stringify(data)
            this.interractedWords = {}
            // console.log(body)
            fetch("/dictionary/interactions/save", {
              method: "POST",
              body: body,
              headers: {
                'X-CSRF-TOKEN': document.querySelector("meta[name='csrf-token']").content,
                "Content-type": "application/json;",
              },
            })
              .then((response) => response.json())
              .then((response) => {

              })
              .catch((error) => {
                console.error("Error:", error);

              });
          },
          __splitAndMemorize(str, offset){
            let arr = str.split(" ");
            arr.forEach(element => {
                if(element){
                    this.interractedWords[element] = (this.interractedWords[element] ? this.interractedWords[element] : 0) + offset
                }
            });
          }
        }));
      });
