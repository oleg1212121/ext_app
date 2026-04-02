@props([
    'options' => null,
    'optgroups' => null,
])

@php
    $baseClasses = 'bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200 text-sm rounded-md px-3 py-1.5 focus:outline-none focus:ring-2 focus:ring-gray-600 dark:focus:ring-gray-500 focus:border-transparent transition';
@endphp

<select {{ $attributes->class($baseClasses) }}>
    @if($optgroups)
        @foreach($optgroups as $groupLabel => $groupOptions)
            <x-forms.optgroup :label="$groupLabel">
                @foreach($groupOptions as $value => $label)
                    <x-forms.option :value="$value" :label="$label" />
                @endforeach
            </x-forms.optgroup>
        @endforeach
    @elseif($options)
        @foreach($options as $value => $label)
            <x-forms.option :value="$value" :label="$label" />
        @endforeach
    @else
        {{ $slot }}
    @endif
</select>
