<link rel="stylesheet" href="{{ asset('css/navigation.css') }}">

@php
    $links = [
        ['href' => url('/crossword'), 'label' => 'Crossword', 'pattern' => 'crossword'],
        ['href' => url('/reader'), 'label' => 'Reader', 'pattern' => 'reader'],
        ['href' => url('/bilinguals/en/ru/simulator'), 'label' => 'Simulator', 'pattern' => 'bilinguals/en/ru/simulator'],
    ];
    $navId = 'nav-' . uniqid();
@endphp

<div class="nav-component">
    <button type="button" class="nav-toggle-btn" onclick="document.getElementById('{{ $navId }}').classList.toggle('nav-menu-open')">
        <span class="nav-hamburger">
            <span></span>
            <span></span>
            <span></span>
        </span>
        Menu
    </button>
    
    <nav id="{{ $navId }}" class="nav-menu">
        <div class="nav-links-container">
            @foreach ($links as $link)
                @php($active = request()->is($link['pattern']))
                <a href="{{ $link['href'] }}"
                   @if($active) aria-current="page" @endif
                   class="nav-link {{ $active ? 'nav-link-active' : 'nav-link-inactive' }}">
                    {{ $link['label'] }}
                </a>
            @endforeach
        </div>

        @if (trim($slot ?? '') !== '')
            <div class="nav-slot-container">
                {{ $slot }}
            </div>
        @endif
    </nav>
</div>

<script>
    // Close menu when clicking outside
    document.addEventListener('click', function(event) {
        const navComponent = event.target.closest('.nav-component');
        if (!navComponent) {
            document.querySelectorAll('.nav-menu').forEach(menu => {
                menu.classList.remove('nav-menu-open');
            });
        }
    });
</script>
