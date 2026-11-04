{{-- Кто держит этап — парят свободно: лицо, имя, сколько задач (горящие — красной точкой). Больше `$max` — «+N». --}}
@php
    $max ??= 4;
    $size ??= 28;
    $name = fn ($p) => match ($p['type']) {
        'user' => $p['model']->firstName(),
        'vendor' => $p['model']->name,
        'robot' => 'Автоматически',
        default => $p['label'] ?? '',
    };
@endphp
@if ($stage['people']->isNotEmpty())
    <div class="people">
        @foreach ($stage['people']->take($max) as $p)
            <span class="person">
                @include('admin.space.variants.face', ['who' => $p, 'size' => $size])
                <span class="person-name">{{ $name($p) }}</span>
                <span class="person-n">{{ $p['n'] }}</span>
                @if ($p['late'])
                    <span class="person-hot" title="Горят">{{ $p['late'] }}</span>
                @endif
            </span>
        @endforeach
        @if ($stage['people']->count() > $max)
            <span class="people-more">+{{ $stage['people']->count() - $max }}</span>
        @endif
    </div>
@endif
