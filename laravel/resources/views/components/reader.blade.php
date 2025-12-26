@extends('components.layouts.crossword')

@section('content')
<div id="readerRoot" class="min-h-screen bg-gradient-to-br from-stone-50 via-white to-stone-50 dark:from-gray-950 dark:via-gray-900 dark:to-gray-950 text-gray-900 dark:text-gray-100">
    <header class="sticky top-0 z-30 backdrop-blur-xl bg-white/80 dark:bg-gray-900/80 border-b border-gray-200/60 dark:border-gray-800/60 shadow-sm">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-4">
            <!-- Main Controls Row -->
            <div class="flex flex-wrap items-center justify-between gap-4 mb-4">
                <div class="flex items-center gap-4">
                    <div>
                        <h1 class="text-xl font-bold bg-gradient-to-r from-gray-900 to-gray-700 dark:from-gray-100 dark:to-gray-300 bg-clip-text text-transparent">Book Reader</h1>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Click any line to reveal translation</p>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <!-- Show All Toggle -->
                    <label class="inline-flex items-center gap-2 cursor-pointer group">
                        <div class="relative">
                            <input id="toggleAll" type="checkbox" class="sr-only peer">
                            <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-blue-500 dark:peer-focus:ring-blue-400 rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-blue-600 dark:peer-checked:bg-blue-500"></div>
                            <span class="text-sm font-medium text-gray-700 dark:text-gray-300 group-hover:text-gray-900 dark:group-hover:text-gray-100 transition">Show All</span>
                        </div>
                    </label>

                    <!-- Font Size -->
                    <div class="hidden sm:flex items-center gap-2 px-3 py-1.5 rounded-lg bg-gray-100 dark:bg-gray-800 border border-gray-200 dark:border-gray-700">
                        <svg class="w-4 h-4 text-gray-600 dark:text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                        </svg>
                        <button id="fontSizeDecrease" type="button" class="w-7 h-7 rounded-md bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-300 font-bold text-sm transition-all active:scale-90" title="Decrease">−</button>
                        <span class="text-xs text-gray-600 dark:text-gray-400 font-mono w-8 text-center" id="fontSizeValue">20</span>
                        <button id="fontSizeIncrease" type="button" class="w-7 h-7 rounded-md bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-300 font-bold text-sm transition-all active:scale-90" title="Increase">+</button>
                    </div>

                    <!-- Layout Toggle -->
                    <button id="layoutToggle" type="button" class="px-4 py-2 rounded-lg bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 border border-gray-200 dark:border-gray-700 text-sm font-medium text-gray-700 dark:text-gray-300 transition-all duration-200 hover:shadow-sm active:scale-95">
                        <span class="hidden sm:inline">Side by Side</span>
                        <span class="sm:hidden">Layout</span>
                    </button>
                </div>
            </div>

            <!-- Audio & Scroll Controls -->
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-3 pt-3 border-t border-gray-200/60 dark:border-gray-800/60">
                <!-- Audio Controls -->
                <div class="flex flex-wrap items-center gap-2">
                    <div class="flex items-center gap-2 px-3 py-1.5 rounded-lg bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800/50">
                        <svg class="w-4 h-4 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.536 8.464a5 5 0 010 7.072m2.828-9.9a9 9 0 010 12.728M6 10a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 01-1 1H7a1 1 0 01-1-1v-4z"></path>
                        </svg>
                        <input id="audioPicker" type="file" accept="audio/*" class="hidden">
                        <button id="pickAudioBtn" type="button" class="text-sm font-medium text-blue-700 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300 transition">Pick Audio</button>
                    </div>
                    <button id="audioPlay" type="button" class="px-3 py-1.5 rounded-lg bg-emerald-500 hover:bg-emerald-600 text-white text-sm font-medium transition-all duration-200 disabled:opacity-50 disabled:cursor-not-allowed shadow-sm hover:shadow active:scale-95" disabled>
                        <svg class="w-4 h-4 inline mr-1" fill="currentColor" viewBox="0 0 20 20"><path d="M6.3 2.841A1.5 1.5 0 004 4.11V15.89a1.5 1.5 0 002.3 1.269l9.344-5.89a1.5 1.5 0 000-2.538L6.3 2.84z"></path></svg>
                        Play
                    </button>
                    <button id="audioPause" type="button" class="px-3 py-1.5 rounded-lg bg-yellow-500 hover:bg-yellow-600 text-white text-sm font-medium transition-all duration-200 disabled:opacity-50 disabled:cursor-not-allowed shadow-sm hover:shadow active:scale-95" disabled>
                        <svg class="w-4 h-4 inline mr-1" fill="currentColor" viewBox="0 0 20 20"><path d="M5.75 3a.75.75 0 00-.75.75v12.5c0 .414.336.75.75.75h1.5a.75.75 0 00.75-.75V3.75A.75.75 0 007.25 3h-1.5zM12.75 3a.75.75 0 00-.75.75v12.5c0 .414.336.75.75.75h1.5a.75.75 0 00.75-.75V3.75a.75.75 0 00-.75-.75h-1.5z"></path></svg>
                        Pause
                    </button>
                    <button id="audioStop" type="button" class="px-3 py-1.5 rounded-lg bg-red-500 hover:bg-red-600 text-white text-sm font-medium transition-all duration-200 disabled:opacity-50 disabled:cursor-not-allowed shadow-sm hover:shadow active:scale-95" disabled>
                        <svg class="w-4 h-4 inline mr-1" fill="currentColor" viewBox="0 0 20 20"><path d="M5.25 3A2.25 2.25 0 003 5.25v9.5A2.25 2.25 0 005.25 17h9.5A2.25 2.25 0 0017 14.75v-9.5A2.25 2.25 0 0014.75 3h-9.5z"></path></svg>
                        Stop
                    </button>
                    <span id="audioStatus" class="text-xs text-gray-500 dark:text-gray-400 font-medium"></span>
                </div>

                <!-- Auto-scroll Controls -->
                <div class="flex flex-wrap items-center gap-2">
                    <div class="flex items-center gap-2 px-3 py-1.5 rounded-lg bg-purple-50 dark:bg-purple-900/20 border border-purple-200 dark:border-purple-800/50">
                        <svg class="w-4 h-4 text-purple-600 dark:text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"></path>
                        </svg>
                        <button id="scrollToggle" type="button" class="text-sm font-medium text-purple-700 dark:text-purple-400 hover:text-purple-800 dark:hover:text-purple-300 transition">Auto Scroll</button>
                    </div>
                    <div class="flex items-center gap-2 px-3 py-1.5 rounded-lg bg-gray-100 dark:bg-gray-800 border border-gray-200 dark:border-gray-700">
                        <button id="scrollSlower" type="button" class="w-7 h-7 rounded-md bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-300 font-bold text-sm transition-all active:scale-90" title="Slower">−</button>
                        <span class="text-xs text-gray-600 dark:text-gray-400 font-mono w-12 text-center"><span id="scrollSpeedVal">80</span> px/s</span>
                        <button id="scrollFaster" type="button" class="w-7 h-7 rounded-md bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-300 font-bold text-sm transition-all active:scale-90" title="Faster">+</button>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <main class="mx-auto max-w-full px-4 sm:px-6 lg:px-12 py-12">
        <div class="bg-white/60 dark:bg-gray-900/60 backdrop-blur-sm rounded-2xl shadow-xl border border-gray-200/50 dark:border-gray-800/50 p-8 md:p-12">
            @foreach($rows as $key => [$en, $ru])
                <article class="reader-row group relative mb-6 pb-6 last:mb-0 last:pb-0 border-b border-gray-200/30 dark:border-gray-800/30 last:border-0 transition-all duration-300 hover:bg-gray-50/50 dark:hover:bg-gray-800/30 rounded-lg px-4 py-3 -mx-4">
                    <button type="button" class="en w-full text-left cursor-pointer hover:text-blue-600 dark:hover:text-blue-400 transition-colors duration-200" 
                            style="line-height: 1.8; font-size: var(--fs, 20px); font-family: 'Georgia', 'Times New Roman', serif;"
                            data-index="{{$key}}">
                        <span class="text-gray-800 dark:text-gray-200">{!! nl2br(e($en)) !!}</span>
                    </button>
                    <div class="ru mt-4 text-emerald-700 dark:text-emerald-400 leading-relaxed hidden transition-all duration-300 ease-in-out transform"
                         style="line-height: 1.75; font-size: calc(var(--fs, 20px) * 0.95); font-family: 'Georgia', 'Times New Roman', serif; opacity: 0.9;">
                        <div class="pl-4 border-l-4 border-emerald-300 dark:border-emerald-600">
                            {!! nl2br(e($ru)) !!}
                        </div>
                    </div>
                </article>
            @endforeach
        </div>
    </main>

    <audio id="readerAudio" class="hidden"></audio>
</div>
@endsection

@push('scripts')
<script>
  document.addEventListener('DOMContentLoaded', () => {
    const root = document.getElementById('readerRoot');

    // Existing controls
    const toggleAll = document.getElementById('toggleAll');
    const layoutToggle = document.getElementById('layoutToggle');
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

    // Auto-scroll controls
    const scrollToggle = document.getElementById('scrollToggle');
    const scrollSlower = document.getElementById('scrollSlower');
    const scrollFaster = document.getElementById('scrollFaster');
    const scrollSpeedVal = document.getElementById('scrollSpeedVal');

    // Initial state
    let sideBySide = false;

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
          }, 200);
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

    // Autoscroll logic
    let scrollActive = false;
    let scrollRAF = null;
    let lastTs = null;
    let speed = 80; // px per second

    function setSpeed(v) {
      speed = Math.max(10, Math.min(300, Number(v) || 80));
      if (scrollSpeedVal) scrollSpeedVal.textContent = String(speed);
    }

    function atBottom() {
      return (window.innerHeight + window.scrollY) >= (document.body.scrollHeight - 2);
    }

    function step(ts) {
      if (!scrollActive) return;
      if (lastTs == null) lastTs = ts;
      const dt = Math.max(0, ts - lastTs) / 1000; // seconds
      lastTs = ts;
      if (!atBottom()) {
        const delta = speed * dt;
        window.scrollBy(0, delta);
        scrollRAF = requestAnimationFrame(step);
      } else {
        // Stop at bottom
        toggleScroll(false);
      }
    }

    function toggleScroll(on) {
      const shouldStart = (typeof on === 'boolean') ? on : !scrollActive;
      if (shouldStart && !scrollActive) {
        scrollActive = true;
        lastTs = null;
        if (scrollToggle) scrollToggle.textContent = 'Pause Scroll';
        scrollRAF = requestAnimationFrame(step);
      } else if (!shouldStart && scrollActive) {
        scrollActive = false;
        if (scrollToggle) scrollToggle.textContent = 'Auto Scroll';
        if (scrollRAF) cancelAnimationFrame(scrollRAF);
        scrollRAF = null;
        lastTs = null;
      }
    }

    scrollToggle?.addEventListener('click', () => toggleScroll());

    scrollSlower?.addEventListener('click', () => {
      setSpeed(speed - 10);
    });

    scrollFaster?.addEventListener('click', () => {
      setSpeed(speed + 10);
    });

    setSpeed(speed);
  });
</script>
@endpush
