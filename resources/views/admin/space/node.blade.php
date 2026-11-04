{{-- Этап конвейера: название, строка дня («+5 сегодня») и кто держит — аватар (у ответственного ещё имя), логотип вендора,
     робот автоматики или значок — с числом рядом. Нуля нет: пусто — только лицо, а у этапа без дела карточка тише.
     Место — две пары координат: широкая раскладка (ПК) и высокая (телефон), выбирает `space_controller` классом. --}}
<div class="space-node conveyor-step{{ $node['idle'] ? ' is-idle' : '' }}" data-node="{{ $key }}" data-total="{{ $node['total'] }}" data-flow="{{ $node['flow'] }}"
     style="--wx: {{ $node['wide'][0] }}px; --wy: {{ $node['wide'][1] }}px; --tx: {{ $node['tall'][0] }}px; --ty: {{ $node['tall'][1] }}px">
    <div class="conveyor-title">{{ $node['title'] }}</div>
    @if ($node['note'])
        <div class="conveyor-note" data-note>{{ $node['note'] }}</div>
    @endif
    @if ($node['holders']->isNotEmpty())
        <div class="conveyor-who">
            @foreach ($node['holders'] as $h)
                @php
                    $model = $h['model'] ?? null;
                    $who = $h['type'].':'.($h['id'] ?? $h['via'] ?? $h['icon'] ?? '');
                    $title = match ($h['type']) {
                        'user' => $model->shortName(),
                        'vendor' => $model->name,
                        'robot' => 'Автоматически',
                        default => $h['label'] ?? null,
                    };
                @endphp
                <span class="who" data-who="{{ $who }}" @if ($title) title="{{ $title }}" @endif>
                    @switch($h['type'])
                        @case('user')
                            <x-ui.avatar :user="$model" :size="28"/>
                            @break
                        @case('vendor')
                            <x-vendor.logo :vendor="$model" class="who-logo"/>
                            @break
                        @case('robot')
                            <span class="avatar avatar-system who-face"><x-ui.icon name="bot" class="size-4"/></span>
                            @break
                        @default
                            <span class="avatar who-face who-icon"><x-ui.icon :name="$h['icon']" class="size-4"/></span>
                    @endswitch
                    @if (($h['lead'] ?? false) && $h['type'] === 'user')
                        <span class="who-name">{{ $model->firstName() }}</span>
                    @endif
                    @if ($h['n'] ?? 0)
                        <span class="who-n{{ ($h['late'] ?? 0) ? ' is-late' : '' }}" data-count="{{ $h['n'] }}">{{ $h['n'] }}</span>
                    @endif
                </span>
            @endforeach
            @if ($node['more'])
                <span class="who-more">+{{ $node['more'] }}</span>
            @endif
        </div>
    @endif
</div>
