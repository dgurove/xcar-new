{{-- Путь сделки у менеджера — ходами (18.11.2026, владелец: «слишком много лишней информации мы им даём», выбрал «ходами», а
     не нашими этапами): «Подтверждение принято», его шаги своими заголовками и наши отрезки — «На согласовании» / «В обработке»;
     у пройденного — сколько длилось, у текущего — время шапки шага (осталось или идёт), впереди серым — его шаги и конец.
     Подпунктов, ответов с файлами и подписей нет. Собирает `Offers\Turn::path`. На фоне, без коробки (11.11.2026). --}}
@php
    $items = \App\Offers\Turn::path($deal, $position, $steps, $blocks);
@endphp
@if (count($items) > 1)
    <section id="path" class="scroll-mt-4 min-w-0 {{ $class ?? '' }}">
        <h2 class="mb-2 px-1 text-sm font-medium text-ink-dim">Путь сделки</h2>
        <div class="steps">
            @foreach ($items as $it)
                <div class="step step--{{ $it['state'] }}">
                    <span class="step-dot">@if ($it['state'] === 'done')<x-ui.icon name="check" class="size-3"/>@endif</span>
                    <div class="step-body">
                        <div class="step-head items-baseline">
                            <span class="step-title flex-1">{{ $it['title'] }}</span>
                            @if ($it['took'])
                                <span class="nums shrink-0 text-sm text-ink-dim">{{ \App\Support\Ago::duration($it['took']) }}</span>
                            @elseif ($it['at'])
                                <span class="nums shrink-0 text-sm text-ink-dim">{!! \App\Support\Ago::html($it['at'], true, words: false) !!}</span>
                            @elseif ($t = $it['turn'])
                                @if ($t->mine && $t->until)
                                    <span @class(['nums shrink-0 text-sm', 'text-danger' => $t->until->isPast(), 'text-urgent' => ! $t->until->isPast()]) data-controller="timer" data-timer-until-value="{{ $t->until->toIso8601String() }}" data-timer-done-value="-" data-timer-coarse-value="true" data-timer-word-value="">{{ $t->until->isPast() ? '−'.\App\Support\Ago::since($t->until) : \App\Support\Ago::left($t->until, '') }}</span>
                                @elseif (! $t->mine && $t->since)
                                    <span class="nums shrink-0 text-sm text-ink-dim" data-controller="timer" data-timer-since-value="{{ $t->since->toIso8601String() }}" data-timer-coarse-value="true">{{ \App\Support\Ago::since($t->since) }}</span>
                                @endif
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </section>
@endif
