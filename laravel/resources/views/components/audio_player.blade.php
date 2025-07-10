            <div>
                <input type="file" accept="audio/*"/>
                <div id="message"></div>
                <audio id="music" controls>
                    Your b  rowser does not support the audio format.
                </audio> 
            </div>
            <style>
                /* General page stuff */
                #audio_player, .info, .error, input {
                display: block;
                width: 427px;
                margin: auto auto auto auto;
                }

                .info {
                    background-color: aqua;     
                }

                .error {
                    background-color: red;
                    color: white;
                }

                /* Audio player styles start here */
                #audio_player {
                padding:2px 2px 1px 2px;
                background-color:black;
                border:2px solid darkgreen;
                border-radius: 9px;
                margin-top: 30px;
                }

                button {
                text-indent:-9999px;
                width:16px;
                height:18px;
                border:none;
                cursor:pointer;
                background:transparent url('buttons.png') no-repeat 0 0; 
                }

                .pause { background-position:-19px 0; }
                .stop { background-position:-38px 0; }

                #volume-bar {
                float:right;
                width: 80px;
                padding:0px 25px 0px 0px;
                }
                .mute { background-position:-95px 0; }
                .unmute { background-position:-114px 0; }
                .replay { background-position:-133px 0; }

                progress {
                color: green;
                font-size: 12px;
                width: 220px;
                height: 12px;
                border: none;
                margin-right: 10px;
                background: #434343;
                border-radius: 9px;
                }
                progress::-moz-progress-bar { 
                color:green;
                background:#434343; 
                }

                progress[value]::-webkit-progress-bar {
                background-color: #434343;
                border-radius: 2px;
                box-shadow: 0 2px 5px rgba(0, 0, 0, 0.25) inset;
                }

                progress[value]::-webkit-progress-value {
                background-color: green;
                }

                input[type=range] {
                -webkit-appearance: none;
                width: 100%;
                margin: 6.8px 0;
                }
                input[type=range]:focus {
                outline: none;
                }
                input[type=range]::-webkit-slider-runnable-track {
                width: 100%;
                height: 4.4px;
                cursor: pointer;
                box-shadow: 0.9px 0.9px 1.7px #002200, 0px 0px 0.9px #003c00;
                background: #205928;
                border-radius: 1px;
                border: 1.1px solid #18d501;
                }
                input[type=range]::-webkit-slider-thumb {
                box-shadow: 2.6px 2.6px 3.7px #00aa00, 0px 0px 2.6px #00c300;
                border: 2.5px solid #83e584;
                height: 18px;
                width: 9px;
                border-radius: 3px;
                background: #439643;
                cursor: pointer;
                -webkit-appearance: none;
                margin-top: -7.9px;
                }
                input[type=range]:focus::-webkit-slider-runnable-track {
                background: #276c30;
                }
                input[type=range]::-moz-range-track {
                width: 100%;
                height: 4.4px;
                cursor: pointer;
                box-shadow: 0.9px 0.9px 1.7px #002200, 0px 0px 0.9px #003c00;
                background: #205928;
                border-radius: 1px;
                border: 1.1px solid #18d501;
                }
                input[type=range]::-moz-range-thumb {
                box-shadow: 2.6px 2.6px 3.7px #00aa00, 0px 0px 2.6px #00c300;
                border: 2.5px solid #83e584;
                height: 18px;
                width: 9px;
                border-radius: 3px;
                background: #439643;
                cursor: pointer;
                }
                input[type=range]::-ms-track {
                width: 100%;
                height: 4.4px;
                cursor: pointer;
                background: transparent;
                border-color: transparent;
                color: transparent;
                }
                input[type=range]::-ms-fill-lower {
                background: #194620;
                border: 1.1px solid #18d501;
                border-radius: 2px;
                box-shadow: 0.9px 0.9px 1.7px #002200, 0px 0px 0.9px #003c00;
                }
                input[type=range]::-ms-fill-upper {
                background: #205928;
                border: 1.1px solid #18d501;
                border-radius: 2px;
                box-shadow: 0.9px 0.9px 1.7px #002200, 0px 0px 0.9px #003c00;
                }
                input[type=range]::-ms-thumb {
                box-shadow: 2.6px 2.6px 3.7px #00aa00, 0px 0px 2.6px #00c300;
                border: 2.5px solid #83e584;
                height: 18px;
                width: 9px;
                border-radius: 3px;
                background: #439643;
                cursor: pointer;
                height: 4.4px;
                }
                input[type=range]:focus::-ms-fill-lower {
                background: #205928;
                }
                input[type=range]:focus::-ms-fill-upper {
                background: #276c30;
                }
            </style>
            <script>
                var player = document.getElementById('music'); 
                function displayMessage(message, canPlay) {
                    var element = document.querySelector('#message');
                    element.innerHTML = message;
                    element.className = canPlay ? 'info' : 'error';
                }
                function playSelectedFile(event) {
                    var file    = this.files[0],
                        type    = file.type,
                        canPlay = player.canPlayType(type),
                        message = 'Can play type "' + type 
                                + '": ' + (canPlay ? canPlay : 'no');
                                
                    displayMessage(message, canPlay);
                    
                    if (canPlay) player.src = URL.createObjectURL(file);
                    else         resetPlayer();
                }

                var inputNode = document.querySelector('input');
                    inputNode.addEventListener('change', playSelectedFile, false);
            </script>