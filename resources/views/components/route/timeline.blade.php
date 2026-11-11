{{-- Путь сделки у менеджера по блокам — тем же видом, что путь в CRM и таймлайн дела ТС (.steps): пройдено галочкой с датой,
     текущий крупнее с «чей ход», впереди серым одним названием — пояснений у ненаступивших этапов нет (11.11.2026, владелец:
     «зачем писать „Оплатите счёт“, если этап оплаты ещё не наступил»). Без номеров — знаменателя нет. --}}
@props(['blocks', 'current' => null, 'steps' => null, 'waiting' => null, 'deal' => null, 'answers' => null, 'lead' => null])
@php
    $passed = true;
@endphp
<div class="steps">
    {{-- Пункты до блоков (`cabinet.deals.path`): «Подтверждение принято», «Беру в работу» — пройденными шагами с датой и временем. --}}
    @foreach ($lead ?? [] as $a)
        <div class="step step--done">
            <span class="step-dot"><x-ui.icon name="check" class="size-3"/></span>
            <div class="step-body">
                <div class="step-head items-baseline">
                    <span class="step-title flex-1">{{ $a['title'] }}@if ($a['note'])<span class="text-sm font-normal text-ink-muted">, {{ $a['note'] }}</span>@endif</span>
                    <span class="nums shrink-0 text-sm text-ink-dim">{!! \App\Support\Ago::html($a['at'], true, words: false) !!}</span>
                </div>
            </div>
        </div>
    @endforeach
    @foreach ($blocks as $block)
        @php
            $isCurrent = $block->id === $current;
            $done = $passed && ! $isCurrent;
            if ($isCurrent) $passed = false;
            $step = $steps?->last(fn ($s) => $s['block'] === $block->name);
        @endphp
        <div class="step step--{{ $isCurrent ? 'current' : ($done ? 'done' : 'next') }}">
            <span class="step-dot">@if ($done)<x-ui.icon name="check" class="size-3"/>@endif</span>
            <div class="step-body">
                <div class="step-head items-baseline">
                    <span class="step-title flex-1">{{ \App\Users\Wording::mine($block->name) }}</span>
                    @if ($done && $step)<span class="nums shrink-0 text-sm text-ink-dim">{{ \App\Support\Ago::html($step['at'], words: false) }}</span>@endif
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
                                        <x-ui.file :name="$media->file_name" :mime="$media->mime_type" :size="$media->humanReadableSize" href="/files/{{ $media->id }}" class="mt-1 pl-5"/>
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
