{{-- Путь ветки маршрута в CRM — как таймлайн дела ТС на парковке (.steps): пройденные блоки галочкой с датой (раскрываются —
     этапы по журналу и «Вернуть на этот шаг»), текущий раскрыт — этап, чей ход и часы словами, действия: главный выход
     лаймовый, остальные серые, письмо вендору; срывы в тупик («Отказ поставщика») — в «···» с подтверждением. Впереди —
     серым, пока путь однозначен (Workflow\Path). --}}
@props(['offer', 'position'])
@php
    use App\Workflow\{Actor, Path, WaitsFor};
    $n = $offer->number;
    $stage = $position->stage;
    $path = Path::for($offer, $position->track);
    // «Подтверждение принято» — не кнопка: в сделку ведёт только «Принять» у подтверждения.
    $exits = $stage->exitsFor(Actor::Staff)->reject(fn ($x) => $x->acceptsBid());
    [$breaks, $moves] = $exits->partition(fn ($x) => $x->to?->block && $x->to->block_id !== $stage->block_id && $x->to->block->isDeadEnd())->all();
    $overdue = $position->isOverdue();
    $tone = $overdue ? 'text-danger' : match ($stage->waits_for->tone()) { 'urgent' => 'text-urgent', 'open' => 'text-accent-text', default => 'text-ink-muted' };
    $clock = $position->deadline_at || $stage->timerMode() === 'stopwatch';
    $answered = $offer->requirements()->whereNotNull('done_at')->with('media')->get()->filter(fn ($r) => $r->media->isNotEmpty());
    $menu = 'route-more-'.$position->id;
@endphp
<div class="steps">
    @foreach ($path as $step)
        @php $block = $step['block']; @endphp
        <div class="step step--{{ $step['state'] }}">
            <span class="step-dot">@if ($step['state'] === Path::DONE)<x-ui.icon name="check" class="size-3"/>@endif</span>
            <div class="step-body">
                @if ($step['state'] === Path::DONE)
                    <details class="step-details">
                        <summary class="step-head">
                            <span class="step-title min-w-0 flex-1 truncate">{{ $block->name }}</span>
                            <span class="flex shrink-0 items-center gap-1.5">@if ($step['at'])<span class="nums text-sm text-ink-dim">{{ $step['at']->translatedFormat('j M') }}</span>@endif<x-ui.icon name="chevron-down" class="step-chevron size-4 text-ink-dim"/></span>
                        </summary>
                        <div class="mt-1 flex flex-col gap-1">
                            @foreach ($step['stages'] as $s)
                                <div class="flex flex-wrap items-baseline gap-x-2 text-sm"><span>{{ $s['stage'] }}</span><span class="nums text-ink-dim">{{ $s['at']->translatedFormat('j M, H:i') }}</span></div>
                            @endforeach
                            @if ($first = $block->stages->first())
                                <form method="post" action="/offers/{{ $n }}/stage" class="mt-1" data-turbo-confirm="Вернуть на «{{ $block->name }}»?">
                                    @csrf<input type="hidden" name="stage_id" value="{{ $first->id }}">
                                    <x-ui.button size="sm" variant="secondary">Вернуть на этот шаг</x-ui.button>
                                </form>
                            @endif
                        </div>
                    </details>
                @elseif ($step['state'] === Path::CURRENT)
                    <div class="step-head">
                        <span class="step-title">{{ $block->name }}</span>
                        @if ($breaks->isNotEmpty())
                            <div class="contents" data-controller="menu">
                                <button type="button" class="btn btn-s btn-quiet btn-round -my-1 ml-auto shrink-0" data-action="menu#toggle" aria-label="Ещё" aria-haspopup="menu" aria-controls="{{ $menu }}"><x-ui.icon name="more" class="size-5"/></button>
                                <div id="{{ $menu }}" class="menu" popover data-menu-target="list" role="menu">
                                    @foreach ($breaks as $exit)
                                        <form method="post" action="/offers/{{ $n }}/exit/{{ $exit->id }}" class="contents" data-turbo-confirm="{{ $exit->confirm ?: $exit->label.'?' }}">@csrf<button class="menu-item w-full text-danger" role="menuitem">{{ $exit->label }}</button></form>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                    @if ($stage->name !== $block->name)<div>{{ $stage->name }}</div>@endif
                    @if ($overdue || $stage->waits_for !== WaitsFor::Nobody || $clock)
                        {{-- Одной строкой: переносы в разметке давали пробел перед запятой. --}}
                        <p class="step-hint {{ $tone }}">@if ($overdue)Срок вышел <span class="nums" data-controller="timer" data-timer-since-value="{{ $position->deadline_at->toIso8601String() }}"></span> назад@else{{ $stage->waits_for->label() }}@if ($position->deadline_at), <span class="nums" data-controller="timer" data-timer-until-value="{{ $position->deadline_at->toIso8601String() }}" data-timer-done-value="-"></span>@elseif ($stage->timerMode() === 'stopwatch'), <span class="nums" data-controller="timer" data-timer-since-value="{{ $position->block_entered_at->toIso8601String() }}"></span>@endif @endif</p>
                    @endif
                    @if ($position->payload)
                        <div class="mt-2 text-sm">@foreach ($position->payload as $k => $v)<div><span class="text-ink-muted">{{ collect($stage->staff_fields)->firstWhere('key', $k)['label'] ?? $k }}:</span> {{ $v }}</div>@endforeach</div>
                    @endif
                    @if ($stage->awaitsManager() && ($req = $offer->requirements()->where('stage_id', $stage->id)->whereNull('done_at')->first()))
                        <div class="mt-1 text-sm text-ink-muted">Менеджеру: «{{ $req->title }}»</div>
                    @endif
                    @foreach ($answered as $r)
                        <div class="mt-1 flex flex-col">
                            @foreach ($r->getMedia('files') as $f)<x-ui.file :name="$f->file_name" :mime="$f->mime_type" :size="$f->humanReadableSize" href="/files/{{ $f->id }}" class="py-1"/>@endforeach
                        </div>
                    @endforeach
                    @if ($moves->isNotEmpty() || $stage->template_id)
                        <div class="mt-3 flex flex-wrap gap-2">
                            @foreach ($moves->values() as $i => $exit)
                                @if ($exit->to?->staff_fields)
                                    <div data-controller="sheet">
                                        <x-ui.button type="button" size="sm" :variant="$i === 0 ? 'primary' : 'secondary'" data-action="sheet#open">{{ $exit->label }}</x-ui.button>
                                        <x-ui.sheet id="exit-{{ $exit->id }}" :title="$exit->label">
                                            <form method="post" action="/offers/{{ $n }}/exit/{{ $exit->id }}" class="flex flex-col gap-4">
                                                @csrf
                                                @foreach ($exit->to->staff_fields as $field)
                                                    <x-route.field :field="$field" :name="'fields['.$field['key'].']'"/>
                                                @endforeach
                                                <x-ui.button block>{{ $exit->label }}</x-ui.button>
                                            </form>
                                        </x-ui.sheet>
                                    </div>
                                @else
                                    <form method="post" action="/offers/{{ $n }}/exit/{{ $exit->id }}" @if ($exit->confirm) data-turbo-confirm="{{ $exit->confirm }}" @endif>
                                        @csrf<x-ui.button size="sm" :variant="$i === 0 ? 'primary' : 'secondary'">{{ $exit->label }}</x-ui.button>
                                    </form>
                                @endif
                            @endforeach
                            @if ($stage->template_id)
                                <a href="/work/mail/new?offer={{ $n }}&template={{ $stage->template_id }}" class="btn btn-quiet btn-s"><x-ui.icon name="send" class="size-4"/>Письмо вендору</a>
                            @endif
                        </div>
                    @endif
                @else
                    <div class="step-head"><span class="step-title">{{ $block->name }}</span></div>
                @endif
            </div>
        </div>
    @endforeach
</div>
