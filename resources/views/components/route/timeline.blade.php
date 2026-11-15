{{-- Путь сделки у менеджера по блокам — тем же видом, что путь в CRM и таймлайн дела ТС (.steps): пройдено галочкой со временем,
     сколько длилось (16.11.2026; было — датой),
     текущий крупнее с «чей ход», впереди серым одним названием — пояснений у ненаступивших этапов нет (11.11.2026, владелец:
     «зачем писать „Оплатите счёт“, если этап оплаты ещё не наступил»). Без номеров — знаменателя нет. --}}
@props(['blocks', 'current' => null, 'steps' => null, 'waiting' => null, 'deal' => null, 'answers' => null, 'lead' => null, 'position' => null])
@php
    $passed = true;
    // Сколько длился этап (16.11.2026, владелец: «вместо дат у этапов — сколько времени заняло, активный — истекающий таймер или
    // счётчик, в зависимости от того, чей ход»): по журналу — от последнего входа в блок до входа в следующий.
    $spans = [];
    $prev = null;
    foreach ($steps ?? [] as $s) {
        if ($s['block'] !== $prev) {
            if ($prev !== null) $spans[$prev]['end'] = $s['at'];
            $spans[$s['block']] = ['start' => $s['at'], 'end' => null];
        }
        $prev = $s['block'];
    }
    $managerTurn = $position && $position->waitsForManager() === \App\Workflow\WaitsFor::Manager && $position->deadline_at;
@endphp
<div class="steps">
    {{-- Пункты до блоков (`cabinet.deals.path`): «Подтверждение принято», «Беру в работу» — пройденными шагами с датой и временем. --}}
    @foreach ($lead ?? [] as $a)
        <div class="step step--done">
            <span class="step-dot"><x-ui.icon name="check" class="size-3"/></span>
            <div class="step-body">
                <div class="step-head items-baseline">
                    <span class="step-title flex-1">{{ $a['title'] }}@if ($a['note'])<span class="text-sm font-normal text-ink-muted">, {{ $a['note'] }}</span>@endif</span>
                    <span class="nums shrink-0 text-sm text-ink-dim">@isset($a['took']){{ \App\Support\Ago::duration($a['took']) }}@else{!! \App\Support\Ago::html($a['at'], true, words: false) !!}@endisset</span>
                </div>
            </div>
        </div>
    @endforeach
    @foreach ($blocks as $block)
        @php
            $isCurrent = $block->id === $current;
            $done = $passed && ! $isCurrent;
            if ($isCurrent) $passed = false;
        @endphp
        <div class="step step--{{ $isCurrent ? 'current' : ($done ? 'done' : 'next') }}">
            <span class="step-dot">@if ($done)<x-ui.icon name="check" class="size-3"/>@endif</span>
            <div class="step-body">
                <div class="step-head items-baseline">
                    <span class="step-title flex-1">{{ \App\Users\Wording::mine($block->name) }}</span>
                    @php $span = $spans[$block->name] ?? null; @endphp
                    @if ($done && $span && $span['end'])
                        <span class="nums shrink-0 text-sm text-ink-dim">{{ \App\Support\Ago::duration((int) $span['start']->diffInSeconds($span['end'])) }}</span>
                    @elseif ($isCurrent && $managerTurn)
                        {{-- Ход менеджера — обратный отсчёт до срока, прошёл — «−3ч» красным. --}}
                        <span @class(['nums shrink-0 text-sm', 'text-danger' => $position->deadline_at->isPast(), 'text-urgent' => ! $position->deadline_at->isPast()]) data-controller="timer" data-timer-until-value="{{ $position->deadline_at->toIso8601String() }}" data-timer-done-value="-" data-timer-coarse-value="true" data-timer-word-value="">{{ \App\Support\Ago::left($position->deadline_at, '') }}</span>
                    @elseif ($isCurrent && ($from = $position?->block_entered_at ?? $span['start'] ?? null))
                        <span class="nums shrink-0 text-sm text-ink-dim" data-controller="timer" data-timer-since-value="{{ $from->toIso8601String() }}" data-timer-coarse-value="true">{{ \App\Support\Ago::since($from) }}</span>
                    @endif
                </div>
                {{-- Ответы менеджера в этом блоке (`cabinet.deals.path`): галочкой, с датой и временем, файлы ответа под ним. --}}
                {{-- Впереди ответов нет: после отката назад прежние ответы дальних этапов — уже не про эту сделку. --}}
                @php $mine = ($done || $isCurrent) ? ($answers?->where('block', $block->id) ?? collect()) : collect(); @endphp
                @if ($mine->isNotEmpty())
                    <ol class="substeps substeps--done">
                        @foreach ($mine as $a)
                            <li class="substep substep--done">
                                <span class="substep-dot"><x-ui.icon name="check" class="size-2.5"/></span>
                                <div class="substep-body">
                                    <div class="substep-head"><span class="substep-title">{{ $a['title'] }}</span><span class="substep-meta nums">@if ($a['note']){{ $a['note'] }}, @endif{!! \App\Support\Ago::html($a['at'], true, words: false) !!}</span></div>
                                    @foreach ($a['files'] as $media)
                                        <x-ui.file :name="$media->file_name" :mime="$media->mime_type" :bytes="$media->size" href="/files/{{ $media->id }}" class="mt-1 pl-5"/>
                                    @endforeach
                                </div>
                            </li>
                        @endforeach
                    </ol>
                @endif
                @if ($isCurrent && $waiting)<p class="step-hint">{{ $waiting }}</p>@endif
            </div>
        </div>
    @endforeach
</div>
