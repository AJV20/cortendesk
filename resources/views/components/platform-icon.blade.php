@props(['platform' => 'unknown', 'size' => 'fs-18'])

@php
    [$icon, $color] = match ($platform) {
        'windows' => ['ri-windows-fill', 'text-info'],
        'macos' => ['ri-apple-fill', 'text-body'],
        'linux' => ['ri-ubuntu-fill', 'text-warning'],
        'android' => ['ri-android-fill', 'text-success'],
        'ios' => ['ri-apple-line', 'text-body'],
        default => ['ri-question-line', 'text-muted'],
    };
@endphp

<i {{ $attributes->merge(['class' => "$icon $color $size align-middle"]) }} title="{{ ucfirst($platform) }}"></i>
