@props(['field', 'sort', 'dir'])

{{--
    A clickable table heading. Nine of these on the device list, so the caret,
    the aria state and the click wiring live here rather than being repeated
    per column — the next sortable table gets them for free.

    The caret is rendered on every heading and only made visible on the active
    one, so the column does not change width when the sort moves to it.
--}}
@php
    $active = $sort === $field;
    $ascending = $active && $dir === 'asc';
@endphp

<th {{ $attributes->merge(['class' => 'rd-sort-th']) }}
    aria-sort="{{ $active ? ($ascending ? 'ascending' : 'descending') : 'none' }}">
    <button type="button" wire:click="sortBy('{{ $field }}')"
            @class(['rd-sort-btn', 'rd-sort-active' => $active])>
        {{ $slot }}<i class="{{ $ascending ? 'ri-arrow-up-s-line' : 'ri-arrow-down-s-line' }}"></i>
    </button>
</th>
