<div class="flex items-stretch">
    <div class="flex items-stretch">
        <input id="audio_input" class=" text-white" type="file" accept="audio/*" />
        <audio id="music" controls>
            Your b rowser does not support the audio format.
        </audio>
        {{-- <div id="message">
                
            </div> --}}
        {{-- <div class="align-center flex items-center">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6 text-white">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m3.75 9v6m3-3H9m1.5-12H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                    </svg>
            </div> --}}

    </div>
    <div class="flex items-stretch text-white">
        <button id="startScroll">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                stroke="currentColor" class="size-6">
                <path stroke-linecap="round" stroke-linejoin="round"
                    d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.347a1.125 1.125 0 0 1 0 1.972l-11.54 6.347a1.125 1.125 0 0 1-1.667-.986V5.653Z" />
            </svg>
        </button>
        <button id="stopScroll">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                stroke="currentColor" class="size-6">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25v13.5m-7.5-13.5v13.5" />
            </svg>
        </button>


    </div>

    <div class="flex items-stretch text-white">
        {{-- <label for="speedControl">Speed:</label> --}}
        <input type="range" id="speedControl" min="1" max="200" value="200">

    </div>

</div>
@push('scripts')
    <script defer>
        var player = document.getElementById('music');

        // function displayMessage(message, canPlay) {
        //     var element = document.querySelector('#message');
        //     element.innerHTML = message;
        //     element.className = canPlay ? 'info' : 'error';

        // }

        function playSelectedFile(event) {
            var file = this.files[0],
                type = file.type,
                canPlay = player.canPlayType(type),
                message = 'Can play type "' + type +
                '": ' + (canPlay ? canPlay : 'no');

            // displayMessage(message, canPlay);

            if (canPlay) {
                player.src = URL.createObjectURL(file);

            } else {
                resetPlayer();

            }

        }

        var inputNode = document.getElementById('audio_input');
        inputNode.addEventListener('change', playSelectedFile, false);
        let scrollInterval;
        let scrollSpeed = 200; // Default speed (1-10)
        let isScrolling = false;

        function startAutoScroll() {
            if (isScrolling) return;

            isScrolling = true;

            scrollInterval = setInterval(() => {
                // Scroll the page by a small amount
                window.scrollBy(0, 1, 'smooth');

                // Stop when reaching the bottom
                // if ((window.innerHeight + window.scrollY) >= document.body.offsetHeight) {
                //     stopAutoScroll();
                // }
            }, scrollSpeed); // Adjust interval for smoother/coarser scrolling
        }

        // Stop autoscroll function
        function stopAutoScroll() {
            clearInterval(scrollInterval);
            isScrolling = false;
        }

        // Change scroll speed
        function setScrollSpeed(speed) {
            scrollSpeed = speed;
            if (isScrolling) {
                stopAutoScroll();
                startAutoScroll();
            }
        }
        // Example usage with UI controls:
        document.addEventListener('DOMContentLoaded', () => {
            // Add buttons to your HTML:
            // <button id="startScroll">Start</button>
            // <button id="stopScroll">Stop</button>
            // <input type="range" id="speedControl" min="1" max="10" value="1">

            document.getElementById('startScroll').addEventListener('click', startAutoScroll);
            document.getElementById('stopScroll').addEventListener('click', stopAutoScroll);

            const speedControl = document.getElementById('speedControl');
            speedControl.addEventListener('input', () => {
                setScrollSpeed(parseInt(speedControl.value));
            });
        });
    </script>
@endpush

@push('styles')
    <style>
        /* Style for scroll controls */
        .scroll-controls {
            /* position: fixed; */
            /* bottom: 20px; */
            /* right: 20px; */
            background: rgba(0, 0, 0, 0.7);
            padding: 10px;
            border-radius: 5px;
            color: white;
            z-index: 1000;
        }

        .scroll-controls button {
            padding: 5px 10px;
            margin: 0 5px;
        }
    </style>
    <style>
        /* General page stuff */
        #audio_player,
        .info,
        .error,
        input {
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
            padding: 2px 2px 1px 2px;
            background-color: black;
            border: 2px solid darkgreen;
            border-radius: 9px;
            margin-top: 30px;
        }

        /* button {
                text-indent: -9999px;
                width: 16px;
                height: 18px;
                border: none;
                cursor: pointer;
                background: transparent url('buttons.png') no-repeat 0 0;
            } */

        .pause {
            background-position: -19px 0;
        }

        .stop {
            background-position: -38px 0;
        }

        #volume-bar {
            float: right;
            width: 80px;
            padding: 0px 25px 0px 0px;
        }

        .mute {
            background-position: -95px 0;
        }

        .unmute {
            background-position: -114px 0;
        }

        .replay {
            background-position: -133px 0;
        }

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
            color: green;
            background: #434343;
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
@endpush
