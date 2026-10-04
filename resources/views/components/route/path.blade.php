{{-- Путь ветки маршрута в CRM — как таймлайн дела ТС на парковке (.steps): пройденные блоки галочкой с датой (раскрываются —
     этапы по журналу, ответы менеджера с файлами и «Вернуть на этот шаг»), текущий раскрыт — этап, чей ход и часы словами,
     просьба к менеджеру, действия: главный выход лаймовый, остальные серые, письмо вендору; срывы в тупик («Отказ
     поставщика») — в «···» с подтверждением. Впереди — серым, пока путь однозначен (Workflow\Path).
     Деньги в шаге, а не рядом: на оплате, пока счёта нет, главное действие — «Выставить счёт»; менеджер сообщил об
     оплате — его заявка тут же с «Поступило» / «Не поступила» (подтверждение само двигает путь, AdvanceOnPayment),
     и выходов «Оплата получена / не поступила» рядом нет — одно действие, а не два несвязанных. --}}
@props(['offer', 'position'])
@php
    use App\Billing\InvoiceState;
    use App\Support\Money;
    use App\Workflow\{Actor, Path, WaitsFor};
    $n = $offer->number;
    $stage = $position->stage;
    $path = Path::for($offer, $position->track);
    // «Подтверждение принято» — не кнопка: в сделку ведёт только «Принять» у подтверждения.
    // Деньги сделки на этапах оплаты; её же ветка решает, какие кнопки видны (гараж «платим мы» — гаражные).
    $deal = $offer->deal?->isActive() ? $offer->deal : null;
    // Вывоз выбирает исходы местом назначения (к менеджеру, к нам, на парковку); «Забрал» ответственного сотрудник жмёт за
    // него — первой кнопкой, а когда вывозим сами, она и есть наша: «Забрали».
    $service = $position->track === \App\Workflow\Track::Service;
    $evacuator = $service ? $offer->evacuator : null;
    $branch = $service ? $offer->pickupDestination() : $deal;
    $keeperExits = $service ? $stage->exitsFor(Actor::Keeper, $branch) : collect();
    $label = fn ($x) => $x->actor === Actor::Keeper && ! $evacuator ? 'Забрали' : $x->label;
    $exits = $keeperExits->concat($stage->exitsFor(Actor::Staff, $branch)->reject(fn ($x) => $x->acceptsBid()));
    // Имена блоков как есть: кто вывозит, видно строкой над путём («Стоит у Сергей К.» имя не склоняет).
    $blockName = fn ($block) => $block->name;
    [$breaks, $moves] = $exits->partition(fn ($x) => $x->to?->block && $x->to->block_id !== $stage->block_id && $x->to->block->isDeadEnd())->all();
    $overdue = $position->isOverdue();
    $tone = $overdue ? 'text-danger' : match ($stage->waits_for->tone()) { 'urgent' => 'text-urgent', 'open' => 'text-accent-text', default => 'text-ink-muted' };
    $clock = $position->deadline_at || $stage->timerMode() === 'stopwatch';
    $requirements = $offer->requirements()->with(['media', 'stage'])->get();
    $menu = 'route-more-'.$position->id;
    $invoices = $deal ? $deal->invoices()->with('claims.media')->get()->reject(fn ($i) => $i->isOwed() || $i->state === InvoiceState::Void) : collect();
    $wantsInvoice = $deal && $invoices->isEmpty() && $stage->exitsFor(Actor::Manager, $deal)->contains(fn ($x) => str_starts_with(mb_strtolower($x->label), 'платёжное поручение'));
    $paidExit = fn ($x) => preg_match('/^оплата (получена|не поступила)/u', mb_strtolower($x->label)) === 1;
    $claims = $moves->contains($paidExit) ? $invoices->flatMap(fn ($i) => $i->claims->map(fn ($p) => [$i, $p])) : collect();
    if ($claims->isNotEmpty()) {
        $moves = $moves->reject($paidExit);
    }
    $undo = \App\Workflow\Actions\StepBack::undoable($offer, $position->track);
    // Черновик без цены продажи (владелец 04.10.2026): первый шаг продажи — назначить цену, а не «Опубликовать». Шаг не
    // из маршрута — он стоит перед ним текущим с «Оценить» (`/offers/{n}/rate`), а этап маршрута ждёт впереди серым.
    $pricing = $position->track === \App\Workflow\Track::Sale && $offer->state === \App\Offers\OfferState::Draft && ! $offer->asking_price && ! $offer->isScheduled() && auth()->user()?->canManageCrm();
@endphp
<div class="steps">
    @if ($pricing)
        <div class="step step--{{ Path::CURRENT }}">
            <span class="step-dot"></span>
            <div class="step-body">
                <div class="step-head"><span class="step-title">Назначение цены</span></div>
                <p class="step-hint text-urgent">Ждём нас</p>
                <form method="post" action="/offers/{{ $n }}/rate" class="mt-3 flex max-w-sm gap-2" data-controller="bid" data-bid-asking-value="0">
                    @csrf
                    <input type="hidden" name="asking_price" data-bid-target="amount" value="">
                    <input type="text" inputmode="decimal" autocomplete="off" enterkeyhint="go" class="field-input field-s nums min-w-0 flex-1" placeholder="Цена продажи, ₽" aria-label="Цена продажи, ₽" data-bid-target="display" data-action="input->bid#input" data-save-into="offer-form" data-save-name="asking_price">
                    <x-ui.button size="sm" class="shrink-0">Оценить</x-ui.button>
                </form>
            </div>
        </div>
    @endif
    @foreach ($path as $step)
        @php
            // Пока цена не назначена, этап маршрута — впереди, без кнопок.
            if ($pricing && $step['state'] === Path::CURRENT) $step['state'] = Path::NEXT;
            $block = $step['block'];
            $answers = $requirements->filter(fn ($r) => $r->done_at && $r->stage?->block_id === $block->id)
                // Вернулись на этап («Оплата не поступила») — прежний ответ на нём уже не в силе, в текущем шаге его нет.
                ->reject(fn ($r) => $step['state'] === Path::CURRENT && $r->stage_id === $position->stage_id && $r->done_at < $position->entered_at)
                ->sortBy('done_at');
        @endphp
        <div class="step step--{{ $step['state'] }}">
            <span class="step-dot">@if ($step['state'] === Path::DONE)<x-ui.icon name="check" class="size-3"/>@endif</span>
            <div class="step-body">
                @if ($step['state'] === Path::DONE)
                    <details class="step-details">
                        <summary class="step-head">
                            <span class="step-title min-w-0 flex-1 truncate">{{ $blockName($block) }}</span>
                            <span class="flex shrink-0 items-center gap-1.5">@if ($step['at'])<span class="nums text-sm text-ink-dim">{{ $step['at']->translatedFormat('j M') }}</span>@endif<x-ui.icon name="chevron-down" class="step-chevron size-4 text-ink-dim"/></span>
                        </summary>
                        <div class="mt-1 flex flex-col gap-1">
                            @foreach ($step['stages'] as $s)
                                <div class="flex flex-wrap items-baseline gap-x-2 text-sm"><span>{{ $s['stage'] }}</span><span class="nums text-ink-dim">{{ $s['at']->translatedFormat('j M, H:i') }}</span></div>
                            @endforeach
                            @foreach ($answers as $r)
                                <div class="text-sm"><span class="text-ink-muted">Менеджер:</span> {{ $r->answer['exit'] ?? $r->title }} <span class="nums text-ink-dim">{{ $r->done_at->translatedFormat('j M, H:i') }}</span></div>
                                @foreach ($r->answer['fields'] ?? [] as $k => $v)<div class="text-sm"><span class="text-ink-muted">{{ collect($r->fields)->firstWhere('key', $k)['label'] ?? $k }}:</span> {{ is_array($v) ? implode(', ', $v) : $v }}</div>@endforeach
                                @foreach ($r->getMedia('files') as $f)<x-ui.file :name="$f->file_name" :mime="$f->mime_type" :size="$f->humanReadableSize" href="/files/{{ $f->id }}" class="py-1"/>@endforeach
                            @endforeach
                            {{-- Назад — на последний этап блока, где предложение было, а не на первый. --}}
                            @php $lastIn = $step['stages']->last(); $target = $lastIn ? ($block->stages->firstWhere('id', $lastIn['stage_id']) ?? $block->stages->firstWhere('name', $lastIn['stage'])) : null; $target ??= $block->stages->first(); @endphp
                            @if ($target && $block->id !== $stage->block_id)
                                <form method="post" action="/offers/{{ $n }}/stage" class="mt-1" data-turbo-confirm="Вернуть на «{{ $target->name }}»?">
                                    @csrf<input type="hidden" name="stage_id" value="{{ $target->id }}">
                                    <x-ui.button size="sm" variant="secondary">Вернуть на этот шаг</x-ui.button>
                                </form>
                            @endif
                        </div>
                    </details>
                @elseif ($step['state'] === Path::CURRENT)
                    <div class="step-head">
                        <span class="step-title">{{ $blockName($block) }}</span>
                        @if ($breaks->isNotEmpty() || $undo)
                            <div class="contents" data-controller="menu">
                                <button type="button" class="btn btn-s btn-quiet btn-round -my-1 ml-auto shrink-0" data-action="menu#toggle" aria-label="Ещё" aria-haspopup="menu" aria-controls="{{ $menu }}"><x-ui.icon name="more" class="size-5"/></button>
                                <div id="{{ $menu }}" class="menu" popover data-menu-target="list" role="menu">
                                    {{-- Отмена шага, которым сюда пришли (StepBack); сразу после нажатия она же — «Отменить» в тосте. --}}
                                    @if ($undo)
                                        @php $undoText = $undo['label'] ? 'Отменить «'.$undo['label'].'»' : 'Вернуть на «'.$undo['to']->name.'»'; @endphp
                                        <form method="post" action="/offers/{{ $n }}/back" class="contents" data-step-back data-turbo-confirm="{{ $undoText }}?" data-turbo-confirm-text="Вернётся «{{ $undo['to']->name }}»" data-turbo-confirm-label="{{ $undo['label'] ? 'Отменить' : 'Вернуть' }}">
                                            @csrf<input type="hidden" name="track" value="{{ $position->track->value }}">
                                            <button class="menu-item w-full" role="menuitem"><x-ui.icon name="undo" class="size-4"/>{{ $undoText }}</button>
                                        </form>
                                    @endif
                                    @foreach ($breaks as $exit)
                                        <form method="post" action="/offers/{{ $n }}/exit/{{ $exit->id }}" class="contents" data-turbo-confirm="{{ $exit->confirm ?: $exit->label.'?' }}">@csrf<button class="menu-item w-full text-danger" role="menuitem">{{ $label($exit) }}</button></form>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                    @if ($wantsInvoice)<div>Счёт ещё не выставлен</div>
                    @elseif ($stage->name !== $block->name)<div>{{ $stage->name }}</div>@endif
                    @if (! $wantsInvoice && ($overdue || $stage->waits_for !== WaitsFor::Nobody || $clock))
                        {{-- Одной строкой: переносы в разметке давали пробел перед запятой. --}}
                        <p class="step-hint {{ $tone }}">@if ($keeperExits->isNotEmpty() && $evacuator && ! $overdue)Ждём {{ $evacuator->shortName() }}@elseif ($overdue)Срок вышел <span class="nums" data-controller="timer" data-timer-since-value="{{ $position->deadline_at->toIso8601String() }}" data-timer-coarse-value="true"></span> назад@else{{ $stage->waits_for->label() }}@if ($position->deadline_at), осталось <span class="nums" data-controller="timer" data-timer-until-value="{{ $position->deadline_at->toIso8601String() }}" data-timer-done-value="-" data-timer-coarse-value="true" data-timer-word-value="">{{ \App\Support\Ago::left($position->deadline_at, '') }}</span>@elseif ($stage->timerMode() === 'stopwatch'), идёт <span class="nums" data-controller="timer" data-timer-since-value="{{ $position->block_entered_at->toIso8601String() }}" data-timer-coarse-value="true"></span>@endif @endif</p>
                    @endif
                    @if ($position->payload)
                        <div class="mt-2 text-sm">@foreach ($position->payload as $k => $v)<div><span class="text-ink-muted">{{ collect($stage->staff_fields)->firstWhere('key', $k)['label'] ?? $k }}:</span> {{ $v }}</div>@endforeach</div>
                    @endif
                    @if (! $wantsInvoice && $stage->awaitsManager($deal) && ($req = $requirements->first(fn ($r) => ! $r->done_at && $r->stage_id === $stage->id)))
                        <div class="mt-1 text-sm text-ink-muted">Менеджеру: «{{ $req->title }}»@if ($req->due_at) до <span class="nums">{{ $req->due_at->translatedFormat('j M, H:i') }}</span>@endif</div>
                    @endif
                    @foreach ($answers as $r)
                        <div class="mt-1 text-sm"><span class="text-ink-muted">Менеджер:</span> {{ $r->answer['exit'] ?? $r->title }}</div>
                        @foreach ($r->answer['fields'] ?? [] as $k => $v)<div class="text-sm"><span class="text-ink-muted">{{ collect($r->fields)->firstWhere('key', $k)['label'] ?? $k }}:</span> {{ is_array($v) ? implode(', ', $v) : $v }}</div>@endforeach
                        @foreach ($r->getMedia('files') as $f)<x-ui.file :name="$f->file_name" :mime="$f->mime_type" :size="$f->humanReadableSize" href="/files/{{ $f->id }}" class="py-1"/>@endforeach
                    @endforeach
                    @foreach ($claims as [$invoice, $p])
                        <div class="mt-3 rounded-(--radius-m) bg-surface-2 p-3">
                            <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                                <span class="text-sm text-ink-muted">Менеджер сообщил об оплате</span>
                                <span class="nums font-semibold">{{ Money::rub($p->amount) }}</span>
                                <span class="nums text-sm text-ink-dim">{{ $p->paid_at->translatedFormat('j M') }}@if ($p->ref), № {{ $p->ref }}@endif</span>
                            </div>
                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                <form method="post" action="/work/payments/{{ $p->id }}/confirm" class="contents" data-turbo-confirm="Поступило {{ Money::rub($p->amount) }}?">@csrf<x-ui.button size="sm">Поступило</x-ui.button></form>
                                <div data-controller="sheet" class="contents">
                                    <x-ui.button type="button" size="sm" variant="secondary" data-action="sheet#open">Не поступила</x-ui.button>
                                    <x-ui.sheet id="reject-{{ $p->id }}" title="Оплата не поступила">
                                        <form method="post" action="/work/payments/{{ $p->id }}/reject" class="flex flex-col gap-3">@csrf<x-ui.field name="reason" label="Что не так" placeholder="Денег на счёте нет, платёжка не читается"/><x-ui.button variant="secondary" block>Не поступила</x-ui.button></form>
                                    </x-ui.sheet>
                                </div>
                                @if ($slip = $p->slip())<x-ui.doc :doc="\App\Support\Docs::media($slip)" class="btn btn-s btn-ghost"><x-ui.icon name="file" class="size-4"/>Платёжка</x-ui.doc>@endif
                            </div>
                        </div>
                    @endforeach
                    @if ($wantsInvoice || $moves->isNotEmpty() || $stage->template_id)
                        <div class="mt-3 flex flex-wrap gap-2">
                            @if ($wantsInvoice)<a href="/work/invoices/new?offer={{ $n }}" class="btn btn-s btn-accent" data-turbo-frame="_top">Выставить счёт</a>@endif
                            @foreach ($moves->values() as $i => $exit)
                                @php $variant = $i === 0 && ! $wantsInvoice ? 'primary' : 'secondary'; @endphp
                                @if ($exit->to?->offer_state === \App\Offers\OfferState::Open && in_array($offer->state, [\App\Offers\OfferState::Draft, \App\Offers\OfferState::Gallery], true))
                                    {{-- «Опубликовать» — сейчас или в слот, как кнопка редактора. --}}
                                    <div data-controller="sheet">
                                        <x-ui.button type="button" size="sm" :variant="$variant" data-action="sheet#open">{{ $label($exit) }}</x-ui.button>
                                        <x-offer.publish-sheet :offer="$offer" id="exit-{{ $exit->id }}" action="/offers/{{ $n }}/exit/{{ $exit->id }}"/>
                                    </div>
                                @elseif ($exit->to?->staff_fields)
                                    <div data-controller="sheet">
                                        <x-ui.button type="button" size="sm" :variant="$variant" data-action="sheet#open">{{ $label($exit) }}</x-ui.button>
                                        <x-ui.sheet id="exit-{{ $exit->id }}" :title="$exit->label">
                                            <form method="post" action="/offers/{{ $n }}/exit/{{ $exit->id }}" class="flex flex-col gap-4">
                                                @csrf
                                                {{-- Контакт и адрес из письма — уже вписаны (`Handover::prefill`), поправить можно. --}}
                                                @php $prefill = \App\Offers\Handover::prefill($exit->to, $offer); @endphp
                                                @foreach ($exit->to->staff_fields as $field)
                                                    <x-route.field :field="$field" :name="'fields['.$field['key'].']'" :value="$prefill[$field['key']] ?? null"/>
                                                @endforeach
                                                <x-ui.button block>{{ $label($exit) }}</x-ui.button>
                                            </form>
                                        </x-ui.sheet>
                                    </div>
                                @else
                                    <form method="post" action="/offers/{{ $n }}/exit/{{ $exit->id }}" @if ($exit->confirm) data-turbo-confirm="{{ $exit->confirm }}" @endif>
                                        @csrf<x-ui.button size="sm" :variant="$variant">{{ $label($exit) }}</x-ui.button>
                                    </form>
                                @endif
                            @endforeach
                            @if ($stage->template_id)
                                <a href="/work/mail/new?offer={{ $n }}&template={{ $stage->template_id }}" class="btn btn-quiet btn-s"><x-ui.icon name="send" class="size-4"/>Письмо вендору</a>
                            @endif
                        </div>
                    @endif
                @else
                    <div class="step-head"><span class="step-title">{{ $blockName($block) }}</span></div>
                @endif
            </div>
        </div>
    @endforeach
</div>
