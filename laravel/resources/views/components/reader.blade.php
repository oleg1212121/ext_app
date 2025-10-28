@extends('components.layouts.crossword')

@section('content')
<div id="readerRoot" class="min-h-screen bg-[#FDFDFC] dark:bg-[#0a0a0a] text-[#1b1b18] dark:text-[#EDEDEC]">
    {{-- <x-navigation></x-navigation> --}}

    <header class="sticky top-0 z-20 bg-white dark:bg-[#0a0a0a] border-b border-[#e3e3e0] dark:border-[#3E3E3A]">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-3 flex flex-col gap-3">
            <div class="flex items-center justify-between gap-4">
                <div class="flex items-baseline gap-3">
                    <h1 class="text-lg font-semibold">Reader</h1>
                    <p class="text-sm text-[#706f6c] dark:text-[#A1A09A] hidden sm:block">Click a line to reveal its translation</p>
                </div>
                <div class="flex items-center gap-3">
                    <label class="inline-flex items-center gap-2 text-sm">
                        <input id="toggleAll" type="checkbox" class="h-3.5 w-3.5 rounded-sm border-[#e3e3e0] dark:border-[#3E3E3A]">
                        <span>Show all translations</span>
                    </label>
                    <div class="hidden sm:flex items-center gap-2 text-sm">
                        <span class="text-[#706f6c] dark:text-[#A1A09A]">Size</span>
                        <input id="fontSize" type="range" min="16" max="38" value="20" class="w-28 accent-[#1b1b18] dark:accent-white">
                    </div>
                                        <button id="layoutToggle" type="button" class="px-3 py-1.5 rounded-sm border border-[#e3e3e0] dark:border-[#3E3E3A] hover:border-black dark:hover:border-white transition">
                        Side by side
                    </button>
                </div>
            </div>

            <div class="flex flex-col md:flex-row md:items-center justify-between gap-3">
                <!-- Audio Controls -->
                <div class="flex items-center gap-2">
                    <input id="audioPicker" type="file" accept="audio/*" class="hidden">
                    <button id="pickAudioBtn" type="button" class="px-3 py-1.5 rounded-sm border border-[#e3e3e0] dark:border-[#3E3E3A] hover:border-black dark:hover:border-white transition">Pick audio</button>
                    <button id="audioPlay" type="button" class="px-3 py-1.5 rounded-sm border border-[#e3e3e0] dark:border-[#3E3E3A] hover:border-black dark:hover:border-white transition disabled:opacity-50" disabled>Play</button>
                    <button id="audioPause" type="button" class="px-3 py-1.5 rounded-sm border border-[#e3e3e0] dark:border-[#3E3E3A] hover:border-black dark:hover:border-white transition disabled:opacity-50" disabled>Pause</button>
                    <button id="audioStop" type="button" class="px-3 py-1.5 rounded-sm border border-[#e3e3e0] dark:border-[#3E3E3A] hover:border-black dark:hover:border-white transition disabled:opacity-50" disabled>Stop</button>
                    <span id="audioStatus" class="text-sm text-[#706f6c] dark:text-[#A1A09A] ml-1"></span>
                </div>

                <!-- Auto-scroll Controls -->
                <div class="flex items-center gap-2">
                    <button id="scrollToggle" type="button" class="px-3 py-1.5 rounded-sm border border-[#e3e3e0] dark:border-[#3E3E3A] hover:border-black dark:hover:border-white transition">Scroll Play</button>
                    <button id="scrollSlower" type="button" class="px-3 py-1.5 rounded-sm border border-[#e3e3e0] dark:border-[#3E3E3A] hover:border-black dark:hover:border-white transition" title="Slower">-</button>
                    <div class="flex items-center gap-2 text-sm">
                        <input id="scrollSpeed" type="range" min="10" max="300" step="5" value="80" class="w-40 accent-[#1b1b18] dark:accent-white">
                        <span class="text-[#706f6c] dark:text-[#A1A09A]"> <span id="scrollSpeedVal">80</span> px/s</span>
                    </div>
                    <button id="scrollFaster" type="button" class="px-3 py-1.5 rounded-sm border border-[#e3e3e0] dark:border-[#3E3E3A] hover:border-black dark:hover:border-white transition" title="Faster">+</button>
                </div>
            </div>
        </div>
    </header>

    <main class="mx-auto  px-1 sm:px-1 lg:px-1 py-6">
        @foreach($rows as $key => [$en, $ru])
            <section class="reader-row group rounded-sm border border-transparent hover:border-[#19140035] dark:hover:border-[#3E3E3A] transition p-4 mb-4 @if($loop->index % 10 == 0) before:absolute before:left-[0.4rem] before:top-0 before:bottom-0 before:border-l before:border-[#e3e3e0] dark:before:border-[#3E3E3A] relative @endif">
                <button type="button" class="en w-full text-left leading-relaxed" style="line-height: 1.6; font-size: var(--fs, 20px);"
                        data-index="{{$key}}">
                    {!! nl2br(e($en)) !!}
                </button>
                <div class="ru mt-2 text-green-700 dark:text-green-300 leading-relaxed hidden"
                     style="line-height: 1.7; font-size: calc(var(--fs, 20px) * 0.9);">
                    {!! nl2br(e($ru)) !!}
                </div>
                            </section>
        @endforeach
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
    const size = document.getElementById('fontSize');

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
    const scrollSpeedRange = document.getElementById('scrollSpeed');
    const scrollSpeedVal = document.getElementById('scrollSpeedVal');

    // Initial state
    let sideBySide = false;

    function applyLayout() {
      document.querySelectorAll('.reader-row').forEach(row => {
        if (sideBySide) {
          row.classList.add('md:grid','md:grid-cols-2','md:gap-6','items-start');
        } else {
          row.classList.remove('md:grid','md:grid-cols-2','md:gap-6','items-start');
        }
      });
      layoutToggle.textContent = sideBySide ? 'Stacked' : 'Side by side';
    }

    function setAllTranslations(visible) {
      document.querySelectorAll('.reader-row .ru').forEach(el => {
        el.classList.toggle('hidden', !visible);
      });
          }

    // Per-row toggle
    document.addEventListener('click', (e) => {
      const btn = e.target.closest('.en');
      if (!btn) return;
      // If "Show all" is on, ignore per-row toggles
      if (toggleAll.checked) return;
      const row = btn.closest('.reader-row');
      const ru = row.querySelector('.ru');
      ru.classList.toggle('hidden');
    });

    // Controls
    toggleAll?.addEventListener('change', (e) => {
      setAllTranslations(e.target.checked);
    });

    layoutToggle?.addEventListener('click', () => {
      sideBySide = !sideBySide;
      applyLayout();
    });

    size?.addEventListener('input', (e) => {
      const v = Number(e.target.value) || 20;
      root.style.setProperty('--fs', v + 'px');
    });


    // Defaults for typography
    root.style.setProperty('--fs', (size ? size.value : 20) + 'px');
        applyLayout();

    // Audio logic
    function setAudioControlsEnabled(enabled) {
      [audioPlay, audioPause, audioStop].forEach(btn => {
        btn.disabled = !enabled;
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
      updateStatus('Ready: ' + (file.name || 'audio'));
    });

    audioPlay?.addEventListener('click', async () => {
      if (!audio.src) return;
      try {
        await audio.play();
        updateStatus('Playing');
      } catch (err) {
        updateStatus('Cannot play: ' + (err?.message || 'unknown error'));
      }
    });

    audioPause?.addEventListener('click', () => {
      if (!audio.src) return;
      audio.pause();
      updateStatus('Paused');
    });

    audioStop?.addEventListener('click', () => {
      if (!audio.src) return;
      audio.pause();
      try { audio.currentTime = 0; } catch (_) {}
      updateStatus('Stopped');
    });

    audio?.addEventListener('ended', () => updateStatus('Ended'));

    // Autoscroll logic
    let scrollActive = false;
    let scrollRAF = null;
    let lastTs = null;
    let speed = Number(scrollSpeedRange?.value || 80); // px per second

    function setSpeed(v) {
      speed = Math.max(0, Math.min(1000, Number(v) || 0));
      if (scrollSpeedRange) scrollSpeedRange.value = String(speed);
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
        scrollToggle.textContent = 'Scroll Pause';
        scrollRAF = requestAnimationFrame(step);
      } else if (!shouldStart && scrollActive) {
        scrollActive = false;
        scrollToggle.textContent = 'Scroll Play';
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

    scrollSpeedRange?.addEventListener('input', (e) => {
      setSpeed(e.target.value);
    });

    setSpeed(speed);
  });
</script>
@endpush
