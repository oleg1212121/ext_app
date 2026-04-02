@props([
    'label' => '',
])

<optgroup label="{{ $label }}">
    {{ $slot }}
</optgroup>
