{{-- Задача сделки — одна карточка на страницу сделки и карточку машины в гараже, пока она ждёт страховую (гаражная
     сделка идёт по тому же маршруту). Данные — `Cabinet\DealController::stepData`. Порядок как у задачи в приложении
     (05.10.2026, владелец): что сделать → где и у кого → ответы страховой (с кем связаться, что прислали) → действия.
     Счетов здесь нет — они в «Расчёте» справа; гараж, где «Расчёта» нет, передаёт `withInvoices`. Путь — `cabinet.deals.path`. --}}
@php
    use App\Offers\DealState;
    use App\Workflow\Asks;
    // Шаг «оплатите счёт»: платёжка живёт у счёта в «Деньгах», а не у просьбы — кнопка ведёт туда.
    $payStep = $requirement && $position?->stage->isPayStep();
    $unpaid = $invoices->filter(fn ($i) => ! $i->isOwed() && $i->state === \App\Billing\InvoiceState::Issued);
    // Оплата, а счёта ещё нет: ход наш (`Position::awaitsInvoice`), просить оплатить нечего — счёт готовим.
    $noInvoice = (bool) $position?->awaitsInvoice();
    // ПРАЙМ без покупателя — ход менеджера: счёт встанет сам, как только он укажет, кому (`Deal::invoiceGap`).
    $needsBuyer = $deal->isActive() && $position?->stage->isPayStep() && $deal->hasContract() && $deal->invoiceGap() === 'buyer';
    $stepInvoices = ($withInvoices ?? false) ? $unpaid : collect();
    // Вернули на оплату («Оплата не поступила») — менеджер видит почему, пока не сообщил об оплате заново.
    $rejected = $payStep && $unpaid->every(fn ($i) => $i->claimed() == 0)
        ? \App\Billing\Payment::whereIn('invoice_id', $unpaid->pluck('id'))->where('state', \App\Billing\PaymentState::Rejected)->latest('id')->first() : null;
    $handover ??= null;
    $showHandover = $handover?->shows();
    // Поля шага, что показаны в получении (контакт, адрес, дата), второй раз в шаге не пишем.
    $payload = collect($position?->payload ?? [])->except($handover?->open ? $handover->keys : []);
    // Ответы — перепиской, по порядку писем: сначала контакт, потом документы. Много — ранние под «Ещё».
    $replies = ($replies ?? collect())->reverse()->values();
    $early = $replies->count() > 3 ? $replies->slice(0, $replies->count() - 2) : collect();
    $late = $replies->slice($early->count());
@endphp
<div class="flex flex-col gap-6">
    @if ($deal->state !== DealState::Active)
        {{-- Закончилась — состояние словом с датой, без своей плашки. --}}
        <p class="max-lg:hidden"><span class="font-medium {{ $deal->state === DealState::Done ? 'text-open' : 'text-danger' }}">{{ $deal->state->label() }}</span>@if ($deal->closed_at) <span class="nums text-sm text-ink-muted">{{ $deal->closed_at->translatedFormat('j M Y, H:i') }}</span>@endif</p>
    @endif

    @if ($deal->state === DealState::Active && $position)
        <div class="box {{ $requirement && $position->isOverdue() ? 'box-urgent' : '' }}">
            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <h2>{{ $position->stage->block?->name ?? 'Идёт работа' }}</h2>
                @if ($noInvoice)<p class="text-sm text-ink-muted">ждём нас</p>@else<x-route.clock :position="$position"/>@endif
            </div>

            @if ($needsBuyer)
                <h3 class="mt-5 text-lg">Укажите покупателя</h3>
                <p class="mt-1 text-ink-muted">Счёт и договор встанут сами</p>
            @elseif ($requirement)
                <h3 class="mt-5 text-lg">{{ $requirement->title }}</h3>
                @if ($rejected)<p class="mt-2 font-medium text-urgent">Оплата <span class="nums">{{ \App\Support\Money::rub($rejected->amount) }}</span> от <span class="nums">{{ $rejected->paid_at->translatedFormat('j M') }}</span> не поступила{{ $rejected->reject_reason ? ': '.$rejected->reject_reason : '' }}</p>@endif
                @if ($requirement->text)<p class="mt-1 whitespace-pre-line text-ink-muted">{{ $requirement->text }}</p>@endif
                {{-- Срок просьбы — тот же, что часы в шапке шага: второй раз его не пишем. --}}
                @if ($requirement->due_at && ! ($position->deadline_at && abs($position->deadline_at->diffInMinutes($requirement->due_at)) < 1))
                    <p class="mt-2 text-sm {{ $requirement->due_at->isPast() ? 'text-urgent' : 'text-ink-muted' }}">до {{ $requirement->due_at->translatedFormat('j M, H:i') }}, <span class="nums font-medium" data-controller="timer" data-timer-until-value="{{ $requirement->due_at->toIso8601String() }}" data-timer-done-value="срок вышел"></span></p>
                @endif
            @elseif ($noInvoice)
                <p class="mt-3 font-medium">Готовим счёт</p>
            @elseif ($about = $position->stage->managerText())
                <p class="mt-3 whitespace-pre-line text-ink-muted">{{ $about }}</p>
            @endif

            @if ($showHandover)@include('cabinet.deals.handover')@endif

            @if ($payload->isNotEmpty())
                <dl class="mt-4 grid grid-cols-1 gap-x-8 gap-y-3 sm:grid-cols-2">
                    @foreach ($payload as $k => $v)
                        <div class="min-w-0"><dt class="text-sm text-ink-dim">{{ collect($position->stage->staff_fields)->firstWhere('key', $k)['label'] ?? $k }}</dt><dd class="nums mt-0.5 break-words font-normal">{{ $v }}</dd></div>
                    @endforeach
                </dl>
            @endif

            @if ($replies->isNotEmpty())@include('cabinet.deals.replies')@endif

            @if ($stepInvoices->isNotEmpty())
                <div class="list mt-5">
                    @foreach ($stepInvoices as $i)@include('cabinet.deals.invoice-row', ['invoice' => $i])<x-billing.pay-status :invoice="$i"/>@endforeach
                </div>
            @endif

            {{-- Действия — после всего, что нужно прочитать. --}}
            @if ($needsBuyer)
                <x-ui.button type="button" block class="mt-5" data-controller="emit" data-action="emit#send" data-emit-event-param="buyer:open">Выбрать покупателя</x-ui.button>
            @endif
            @if ($requirement && ! $needsBuyer)
                @if ($payStep && $unpaid->isNotEmpty())
                    <div class="mt-5 flex flex-wrap gap-2">
                        @foreach ($unpaid as $i)<x-ui.button :href="'/account/money/deals/'.$deal->id" size="s">Оплатить{{ $unpaid->count() > 1 ? ' '.$i->label() : '' }}</x-ui.button>@endforeach
                    </div>
                @else
                    @if ($requirement->asks === Asks::Document)
                        <div class="mt-5" data-controller="photos" data-photos-url-value="/deals/{{ $deal->id }}/files">
                            <input type="file" accept="image/*,.pdf,.heic" multiple hidden data-photos-target="input" data-action="change->photos#upload">
                            @include('cabinet.deals.files', ['requirement' => $requirement])
                            <div hidden data-photos-target="progress" class="my-2">
                                <div class="mb-1 text-sm text-ink-muted" data-label></div>
                                <div class="h-1.5 overflow-hidden rounded-full bg-surface-3"><div class="h-full bg-accent transition-[width]" data-bar style="width:0"></div></div>
                            </div>
                            <x-ui.button type="button" variant="secondary" size="s" data-action="photos#pick"><x-ui.icon name="camera" class="size-4"/> Приложить</x-ui.button>
                            @if ($errors->has('files'))<p class="field-error mt-2">{{ $errors->first('files') }}</p>@endif
                        </div>
                    @endif
                    <form method="post" action="/deals/{{ $deal->id }}/reply" class="mt-5 flex flex-col gap-4">
                        @csrf
                        @if ($requirement->asks === Asks::Fields)
                            @foreach ($requirement->fields as $field)
                                <x-route.field :field="$field" :name="'fields['.$field['key'].']'"/>
                            @endforeach
                        @endif
                        @if ($errors->has('exit'))<p class="field-error">{{ $errors->first('exit') }}</p>@endif
                        {{-- Один исход — во всю ширину, два — в ряд одной ширины, как ответы в диалоге приложения; больше — переносом. --}}
                        <div @class(['gap-3', 'grid' => $exits->count() <= 2, 'grid-cols-2' => $exits->count() === 2, 'flex flex-wrap' => $exits->count() > 2])>
                            @foreach ($exits as $exit)
                                <x-ui.button name="exit" :value="$exit->id" :variant="$loop->first ? 'primary' : 'secondary'" :data-turbo-confirm="$exit->confirm" class="min-w-0 px-4">{{ $exit->label }}</x-ui.button>
                            @endforeach
                        </div>
                    </form>
                @endif
            @endif
            @if ($handover?->servicePick && $showHandover)
                <form method="post" action="/deals/{{ $deal->id }}/picked" class="mt-5" data-turbo-confirm="Забрали автомобиль?" data-turbo-confirm-label="Забрал">
                    @csrf<x-ui.button block>Автомобиль забрал</x-ui.button>
                </form>
            @endif
        </div>
    @elseif ($deal->state === DealState::Active)
        <x-ui.empty>Сделка пока не в работе. Мы напишем, когда что-то изменится</x-ui.empty>
    @endif

    {{-- Нет текущего шага (сделка закрыта или ещё не в работе) — ответы своей карточкой. --}}
    @if ($replies->isNotEmpty() && ! ($deal->state === DealState::Active && $position))
        <div class="box">@include('cabinet.deals.replies', ['bare' => true])</div>
    @endif
</div>
