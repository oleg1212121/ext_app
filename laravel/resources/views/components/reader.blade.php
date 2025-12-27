@extends('components.layouts.crossword')

@section('content')
<div id="readerRoot" class="min-h-screen flex flex-col bg-orange-50 dark:bg-gray-900">
    <!-- Top Toolbar - Fixed -->
    <header class="flex-none bg-white dark:bg-gray-800 border-b-2 border-gray-400 dark:border-gray-600 shadow-md">
        <div class="flex flex-wrap items-center justify-center gap-3 px-4 py-3">
            <!-- Title -->
            <div class="flex items-center gap-2">
                <h1 class="text-lg font-semibold text-gray-800 dark:text-gray-100">Book Reader</h1>
            </div>

            <div class="h-6 w-px bg-gray-400 dark:bg-gray-600"></div>

            <!-- Font Size Controls -->
            <div class="flex items-center gap-1">
                <button id="fontSizeDecrease" type="button" class="w-8 h-8 flex items-center justify-center bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 hover:cursor-pointer text-gray-700 dark:text-gray-200 rounded transition">
                    <span class="text-lg font-semibold">−</span>
                </button>
                <span class="text-xs text-gray-600 dark:text-gray-400 font-mono w-8 text-center" id="fontSizeValue">20</span>
                <button id="fontSizeIncrease" type="button" class="w-8 h-8 flex items-center justify-center bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 hover:cursor-pointer text-gray-700 dark:text-gray-200 rounded transition">
                    <span class="text-lg font-semibold">+</span>
                </button>
            </div>

            <div class="h-6 w-px bg-gray-400 dark:bg-gray-600"></div>

            <!-- Show All Toggle -->
            <label class="inline-flex items-center gap-2 cursor-pointer">
                <input id="toggleAll" type="checkbox" class="w-4 h-4 text-gray-700 dark:text-gray-300 rounded border-gray-300 dark:border-gray-600 focus:ring-gray-600 dark:bg-gray-700">
                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Show All</span>
            </label>

            <!-- Layout Toggle -->
            <button id="layoutToggle" type="button" class="px-3 py-1.5 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 hover:cursor-pointer text-gray-700 dark:text-gray-200 text-sm rounded transition">
                Side by Side
            </button>

            <!-- Width Toggle -->
            <button id="widthToggle" type="button" class="px-3 py-1.5 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 hover:cursor-pointer text-gray-700 dark:text-gray-200 text-sm rounded transition">
                Wide Mode
            </button>

            <div class="h-6 w-px bg-gray-400 dark:bg-gray-600"></div>

            <!-- Audio Controls -->
            <div class="flex items-center gap-2">
                <input id="audioPicker" type="file" accept="audio/*" class="hidden">
                <button id="pickAudioBtn" type="button" class="px-3 py-1.5 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 hover:cursor-pointer text-gray-700 dark:text-gray-200 text-sm rounded transition">
                    Pick Audio
                </button>
                <button id="audioPlay" type="button" class="px-3 py-1.5 bg-gray-700 dark:bg-gray-600 hover:bg-gray-800 dark:hover:bg-gray-500 hover:cursor-pointer text-white text-sm rounded transition disabled:opacity-50 disabled:cursor-not-allowed" disabled>
                    Play
                </button>
                <button id="audioPause" type="button" class="px-3 py-1.5 bg-gray-700 dark:bg-gray-600 hover:bg-gray-800 dark:hover:bg-gray-500 hover:cursor-pointer text-white text-sm rounded transition disabled:opacity-50 disabled:cursor-not-allowed" disabled>
                    Pause
                </button>
                <button id="audioStop" type="button" class="px-3 py-1.5 bg-gray-700 dark:bg-gray-600 hover:bg-gray-800 dark:hover:bg-gray-500 hover:cursor-pointer text-white text-sm rounded transition disabled:opacity-50 disabled:cursor-not-allowed" disabled>
                    Stop
                </button>
                <span id="audioStatus" class="text-xs text-gray-600 dark:text-gray-400"></span>
            </div>
        </div>
    </header>

    <!-- Main Content Area -->
    <main class="flex-1 overflow-y-auto bg-orange-100 dark:bg-gray-800 pb-5">
        <div id="contentContainer" class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
            <div class="bg-white dark:bg-gray-700 rounded-md shadow-sm border-2 border-gray-400 dark:border-gray-600 p-6">
                @foreach($rows as $key => [$en, $ru])
                    <article class="reader-row mb-4 pb-4 last:mb-0 last:pb-0 border-b border-gray-200 dark:border-gray-600 last:border-0">
                        <button type="button" class="en w-full text-left cursor-pointer text-gray-800 dark:text-gray-200 hover:text-gray-600 dark:hover:text-gray-400 transition-colors duration-150"
                                style="line-height: 1.8; font-size: var(--fs, 20px); font-family: 'Georgia', 'Times New Roman', serif;"
                                data-index="{{$key}}">
                            {!! nl2br(e($en)) !!}
                        </button>
                        <div class="ru mt-3 text-emerald-700 dark:text-emerald-400 leading-relaxed hidden transition-opacity duration-150"
                             style="line-height: 1.75; font-size: calc(var(--fs, 20px) * 0.95); font-family: 'Georgia', 'Times New Roman', serif; opacity: 0;">
                            <div class="pl-4 border-l-2 border-emerald-400 dark:border-emerald-500">
                                {!! nl2br(e($ru)) !!}
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>
        </div>
    </main>

    <audio id="readerAudio" class="hidden"></audio>
</div>
@endsection

@push('scripts')
<script>
  document.addEventListener('DOMContentLoaded', () => {
    const root = document.getElementById('readerRoot');

    // Controls
    const toggleAll = document.getElementById('toggleAll');
    const layoutToggle = document.getElementById('layoutToggle');
    const widthToggle = document.getElementById('widthToggle');
    const contentContainer = document.getElementById('contentContainer');
    const fontSizeDecrease = document.getElementById('fontSizeDecrease');
    const fontSizeIncrease = document.getElementById('fontSizeIncrease');
    const fontSizeValue = document.getElementById('fontSizeValue');

    // Audio controls
    const pickAudioBtn = document.getElementById('pickAudioBtn');
    const audioPicker = document.getElementById('audioPicker');
    const audio = document.getElementById('readerAudio');
    const audioPlay = document.getElementById('audioPlay');
    const audioPause = document.getElementById('audioPause');
    const audioStop = document.getElementById('audioStop');
    const audioStatus = document.getElementById('audioStatus');

    // Initial state
    let sideBySide = false;
    let wideMode = false;

    function applyLayout() {
      document.querySelectorAll('.reader-row').forEach(row => {
        if (sideBySide) {
          row.classList.add('md:grid', 'md:grid-cols-2', 'md:gap-8', 'items-start');
        } else {
          row.classList.remove('md:grid', 'md:grid-cols-2', 'md:gap-8', 'items-start');
        }
      });
      if (layoutToggle) {
        const btnText = layoutToggle.querySelector('span');
        if (btnText) {
          btnText.textContent = sideBySide ? 'Stacked' : 'Side by Side';
        } else {
          layoutToggle.textContent = sideBySide ? 'Stacked' : 'Side by Side';
        }
      }
    }

    function setAllTranslations(visible) {
      document.querySelectorAll('.reader-row .ru').forEach(el => {
        if (visible) {
          el.classList.remove('hidden');
          el.style.opacity = '1';
        } else {
          el.classList.add('hidden');
        }
      });
    }

    // Per-row toggle with smooth animation
    document.addEventListener('click', (e) => {
      const btn = e.target.closest('.en');
      if (!btn) return;
      // If "Show all" is on, ignore per-row toggles
      if (toggleAll && toggleAll.checked) return;
      const row = btn.closest('.reader-row');
      const ru = row.querySelector('.ru');
      if (ru) {
        const isHidden = ru.classList.contains('hidden');
        if (isHidden) {
          ru.classList.remove('hidden');
          // Trigger reflow for animation
          ru.offsetHeight;
          ru.style.opacity = '1';
        } else {
          ru.style.opacity = '0';
          setTimeout(() => {
            ru.classList.add('hidden');
          }, 150);
        }
      }
    });

    // Controls
    toggleAll?.addEventListener('change', (e) => {
      setAllTranslations(e.target.checked);
    });

    layoutToggle?.addEventListener('click', () => {
      sideBySide = !sideBySide;
      applyLayout();
    });

    // Width toggle
    widthToggle?.addEventListener('click', () => {
      wideMode = !wideMode;
      if (wideMode) {
        contentContainer.classList.remove('max-w-7xl');
        contentContainer.style.width = '95%';
        widthToggle.textContent = 'Normal Mode';
      } else {
        contentContainer.style.width = '';
        contentContainer.classList.add('max-w-7xl');
        widthToggle.textContent = 'Wide Mode';
      }
    });

    // Font size control
    let fontSize = 20;
    const minFontSize = 16;
    const maxFontSize = 38;

    function setFontSize(v) {
      fontSize = Math.max(minFontSize, Math.min(maxFontSize, Number(v) || 20));
      root.style.setProperty('--fs', fontSize + 'px');
      if (fontSizeValue) {
        fontSizeValue.textContent = fontSize;
      }
    }

    fontSizeDecrease?.addEventListener('click', () => {
      setFontSize(fontSize - 2);
    });

    fontSizeIncrease?.addEventListener('click', () => {
      setFontSize(fontSize + 2);
    });

    // Defaults for typography
    setFontSize(fontSize);
    applyLayout();

    // Audio logic
    function setAudioControlsEnabled(enabled) {
      [audioPlay, audioPause, audioStop].forEach(btn => {
        if (btn) btn.disabled = !enabled;
      });
    }

    function updateStatus(text) {
      if (audioStatus) audioStatus.textContent = text || '';
    }

    pickAudioBtn?.addEventListener('click', () => audioPicker?.click());

    audioPicker?.addEventListener('change', () => {
      const file = audioPicker.files && audioPicker.files[0];
      if (!file) return;
      if (audio.src) {
        try { URL.revokeObjectURL(audio.src); } catch (_) {}
      }
      audio.src = URL.createObjectURL(file);
      audio.load();
      setAudioControlsEnabled(true);
      const fileName = file.name.length > 20 ? file.name.substring(0, 20) + '...' : file.name;
      updateStatus('Ready: ' + fileName);
    });

    audioPlay?.addEventListener('click', async () => {
      if (!audio.src) return;
      try {
        await audio.play();
        updateStatus('Playing ▶');
      } catch (err) {
        updateStatus('Cannot play: ' + (err?.message || 'unknown error'));
      }
    });

    audioPause?.addEventListener('click', () => {
      if (!audio.src) return;
      audio.pause();
      updateStatus('Paused ⏸');
    });

    audioStop?.addEventListener('click', () => {
      if (!audio.src) return;
      audio.pause();
      try { audio.currentTime = 0; } catch (_) {}
      updateStatus('Stopped ⏹');
    });

    audio?.addEventListener('ended', () => updateStatus('Ended'));

    // Spacebar to pause/play audio
    document.addEventListener('keydown', (e) => {
      // Only trigger if spacebar is pressed and not typing in an input/textarea
      if (e.code === 'Space' && !['INPUT', 'TEXTAREA'].includes(e.target.tagName)) {
        e.preventDefault(); // Prevent page scroll
        if (!audio.src) return;

        if (audio.paused) {
          audio.play().then(() => {
            updateStatus('Playing ▶');
          }).catch(err => {
            updateStatus('Cannot play: ' + (err?.message || 'unknown error'));
          });
        } else {
          audio.pause();
          updateStatus('Paused ⏸');
        }
      }
    });
  });
</script>
@endpush
