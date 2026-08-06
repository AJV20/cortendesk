@props(['platform' => 'unknown', 'size' => 'fs-18'])

@php
    // Accept both the console's slugs ('macos') and the RustDesk-style names
    // clients sync into address books ('Mac OS', 'Windows').
    $platform = str_replace(' ', '', strtolower($platform ?: 'unknown'));
    $platform = $platform === 'macos' || $platform === 'mac' ? 'macos' : $platform;
    [$icon, $color] = match ($platform) {
        'windows' => ['ri-windows-fill', 'text-info'],
        'macos' => ['ri-apple-fill', 'text-body'],
        'linux' => ['ri-ubuntu-fill', 'text-warning'],
        'android' => ['ri-android-fill', 'text-success'],
        'ios' => ['ri-apple-line', 'text-body'],
        default => ['ri-question-line', 'text-muted'],
    };
    $label = ['windows' => 'Windows', 'macos' => 'macOS', 'linux' => 'Linux', 'android' => 'Android', 'ios' => 'iOS'][$platform] ?? 'Unknown';
@endphp

<i {{ $attributes->merge(['class' => "$icon $color $size align-middle"]) }} title="{{ $label }}"></i>
