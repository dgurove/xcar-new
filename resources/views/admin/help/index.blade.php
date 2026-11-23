{{-- «Настройки → Как работать» (24.11.2026, владелец: «кто что будет делать в системе», «простой мануал, чтобы поняли даже самые
     тупые»): кто что делает, путь сделки по поставщику, карточки «как сделать» со снимками. Чья часть — `?who=` (по умолчанию по
     «Ответственным», `Help::who`); текст и шаги — `App\Support\Help`. --}}
@php
    use App\Support\Help;
    $people = Help::PEOPLE;
    $howto = Help::howto();
    $shown = $who === 'all' ? array_keys($people) : [$who];
    $name = fn (string $k) => $people[$k]['name'] ?? Help::OTHERS[$k];
    // Снимок — не шире своего размера (снят вдвое плотнее), на телефоне — по ширине экрана; нажатие открывает его целиком: снимок
    // ПК на телефоне мелкий, а открытый увеличивается пальцами.
    $shot = fn (array $s) => '<a href="/help/'.$s[0].'.webp" data-turbo="false" class="block"><img src="/help/'.$s[0].'.webp" width="'.$s[1].'" height="'.$s[2].'" alt="" loading="lazy" class="help-shot" style="max-width: '.$s[1].'px"></a>';
@endphp
<x-ui.cabinet title="Как работать">
    <nav class="segment [grid-auto-columns:auto]" aria-label="Чья часть">
        @foreach ($people as $key => $p)
            <a href="/settings/help?who={{ $key }}" data-turbo-action="replace" @if ($who === $key) aria-current="true" @endif>{{ $p['name'] }}</a>
        @endforeach
        <a href="/settings/help?who=all" data-turbo-action="replace" @if ($who === 'all') aria-current="true" @endif>Все</a>
    </nav>

    <section class="box flex flex-col gap-4">
        <h2>Кто что делает</h2>
        <div class="grid gap-4 md:grid-cols-3">
            @foreach ($people as $key => $p)
                @php $open = in_array($key, $shown, true); @endphp
                <div @class(['flex flex-col gap-3', 'text-ink-muted' => ! $open])>
                    <h3 class="help-who text-base font-medium"><span class="help-dot help-dot--{{ $key }}"></span>{{ $p['name'] }}</h3>
                    <p class="text-sm">{{ $p['role'] }}</p>
                    {{-- Вкладки и кнопки — только своей части: чужие на телефоне растягивали экран вдвое. --}}
                    @if ($open)
                    <div class="flex flex-col gap-1.5">
                        <span class="text-sm text-ink-muted">Вкладки</span>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach (Help::DUTIES[$key]['tabs'] as $tab)<span class="tag">{{ $tab }}</span>@endforeach
                        </div>
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <span class="text-sm text-ink-muted">Кнопки</span>
                        <ul class="flex flex-col gap-1 text-sm">
                            @foreach (Help::DUTIES[$key]['buttons'] as $button)<li>{{ $button }}</li>@endforeach
                        </ul>
                    </div>
                    @endif
                </div>
            @endforeach
        </div>
        <p class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-ink-muted">
            @foreach (Help::OTHERS as $key => $label)
                <span class="help-who"><span class="help-dot help-dot--{{ $key }}"></span>{{ $label }}</span>
            @endforeach
        </p>
    </section>

    <h2 class="pt-2 text-lg font-medium">Путь сделки</h2>
    @foreach (Help::routes() as $route)
        <section class="box flex flex-col gap-3">
            <h2>{{ $route['name'] }}</h2>
            @foreach (array_filter([['', $route['steps']], $route['apart'] ?? null]) as [$head, $steps])
                <div>
                    @if ($head !== '')<div class="list-head">{{ $head }}</div>@endif
                    <div class="list">
                        @foreach ($steps as [$step, $by, $what])
                            @php $mine = in_array($by, $shown, true); @endphp
                            <div class="row items-start">
                                <span class="min-w-0 flex-1">
                                    <span @class(['block', 'font-medium' => $mine])>{{ $step }}</span>
                                    <span class="row-sub">{{ $what }}</span>
                                </span>
                                <span @class(['help-who shrink-0 text-sm', 'text-ink-muted' => ! $mine])><span class="help-dot help-dot--{{ $by }}"></span>{{ $name($by) }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </section>
    @endforeach

    @foreach ($shown as $key)
        <h2 class="help-who pt-2 text-lg font-medium"><span class="help-dot help-dot--{{ $key }}"></span>{{ $people[$key]['name'] }}: как сделать</h2>
        @foreach ($howto[$key] as $card)
            <section class="box flex flex-col gap-4">
                <h2>{{ $card['title'] }}</h2>
                <ol class="help-steps">
                    @foreach ($card['steps'] as $i => $line)
                        <li><span class="help-num">{{ $i + 1 }}</span><span>{{ $line }}</span></li>
                    @endforeach
                </ol>
                @isset($card['shot']){!! $shot($card['shot']) !!}@endisset
                @isset($card['more']){!! $shot($card['more']) !!}@endisset
            </section>
        @endforeach
    @endforeach

    <h2 class="pt-2 text-lg font-medium">Если что-то не так</h2>
    <div class="list">
        @foreach (Help::TROUBLES as [$what, $do])
            <div class="row items-start">
                <span class="min-w-0 flex-1">
                    <span class="block">{{ $what }}</span>
                    <span class="row-sub">{{ $do }}</span>
                </span>
            </div>
        @endforeach
    </div>
</x-ui.cabinet>
