{{-- Путь сделки у менеджера по блокам — тем же видом, что путь в CRM и таймлайн дела ТС (.steps): пройдено галочкой с датой,
     текущий крупнее с «чей ход», впереди серым — и под ним, что там понадобится от менеджера (просьба первого его этапа
     блока: «Оплатите счёт», «Заберите автомобиль»). Без номеров — знаменателя нет. --}}
@props(['blocks', 'current' => null, 'steps' => null, 'waiting' => null, 'deal' => null, 'answers' => null])
@php
    $passed = true;
    // Этап, куда эта сделка не попадёт (все входы — чужой ветки: «забирает сам» у сделки, где отдаём мы), подсказкой не идёт.
    $incoming = $deal ? \App\Workflow\Outcome::whereIn('to_stage_id', $blocks->flatMap->stages->pluck('id'))->get()->groupBy('to_stage_id') : collect();
    $reaches = fn ($s) => ! $incoming->has($s->id) || $incoming[$s->id]->contains(fn ($e) => $e->fits($deal));
@endphp
<div class="steps">
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
                @if (! $isCurrent && ! $done && $deal && ($ask = $block->stages->first(fn ($s) => $s->ask_title && $s->awaitsManager($deal) && $reaches($s))))
                    <p class="step-hint">{{ \App\Users\Wording::mine($ask->ask_title) }}</p>
                @endif
            </div>
        </div>
    @endforeach
</div>
