{{-- Текущий шаг сделки и путь — одно на страницу сделки и карточку машины в гараже, пока она ждёт страховую
     (гаражная сделка идёт по тому же маршруту). Данные — `Cabinet\DealController::stepData`. --}}
@php
    use App\Offers\DealState;
    use App\Workflow\Asks;
    // Конечный этап (выходов нет — «Сделка закрыта») пройден: в пути он галочкой, как в CRM.
    $currentBlock = $position?->stage->exits->isNotEmpty() ? $position->stage->block_id : null;
    // Шаг «оплатите счёт»: платёжка живёт у счёта в «Деньгах», а не у просьбы — кнопка ведёт туда.
    $payStep = $requirement && $exits->contains(fn ($x) => str_starts_with(mb_strtolower($x->label), 'платёжное поручение'));
    $unpaid = $invoices->filter(fn ($i) => ! $i->isOwed() && $i->state === \App\Billing\InvoiceState::Issued);
    // Оплата, а счёта ещё нет: просить оплатить и приложить платёжку нечего — счёт готовим.
    $noInvoice = $payStep && $invoices->reject(fn ($i) => $i->isOwed())->isEmpty();
    // В шаге — счета, которые ещё ждут оплаты; оплаченные и аннулированные — отдельной карточкой «Счета» ниже пути.
    $stepInvoices = $invoices->filter(fn ($i) => $i->state === \App\Billing\InvoiceState::Issued);
    $pastInvoices = $invoices->diff($stepInvoices);
    // Вернули на оплату («Оплата не поступила») — менеджер видит почему, пока не сообщил об оплате заново.
    $rejected = $payStep && $stepInvoices->every(fn ($i) => $i->claimed() == 0)
        ? \App\Billing\Payment::whereIn('invoice_id', $stepInvoices->pluck('id'))->where('state', \App\Billing\PaymentState::Rejected)->latest('id')->first() : null;
    $waiting = $position ? match ($position->stage->waits_for) {
        \App\Workflow\WaitsFor::Manager => 'Ваш ход', \App\Workflow\WaitsFor::Supplier => 'ждём поставщика', \App\Workflow\WaitsFor::Us => 'ждём нас', default => null,
    } : null;
    if ($noInvoice) {
        $waiting = 'ждём нас';
    }
    // Получение автомобиля (`Offers\Handover`): просьба шага и есть «заберите» — его строки внутри шага, иначе — карточкой под ним.
    $handover ??= null;
    $embedHandover = $handover && $requirement && $handover->inStep($position, $deal);
    // Поля шага, что показаны в получении (контакт, адрес, дата), второй раз в шаге не пишем.
    $payload = collect($position?->payload ?? [])->except($handover?->open ? $handover->keys : []);
@endphp
<div class="space-y-6">
            @if ($deal->state !== DealState::Active)
                {{-- Закончилась — состояние словом с датой, без своей плашки. --}}
                <p class="max-lg:hidden"><span class="font-medium {{ $deal->state === DealState::Done ? 'text-open' : 'text-danger' }}">{{ $deal->state->label() }}</span>@if ($deal->closed_at) <span class="nums text-sm text-ink-muted">{{ $deal->closed_at->translatedFormat('j M Y, H:i') }}</span>@endif</p>
            @elseif ($position)
                {{-- Текущий этап — одной карточкой и первым. Просьба живёт внутри неё; ждут человека и срок вышел — карточка тревожная. --}}
                <div class="box {{ $requirement && $position->isOverdue() ? 'box-urgent' : '' }}">
                    <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                        <h2>{{ $position->stage->block?->name ?? 'Идёт работа' }}</h2>
                        @if ($noInvoice)<p class="text-sm text-ink-muted">ждём нас</p>@else<x-route.clock :position="$position"/>@endif
                    </div>
                    {{-- Есть просьба — её текст и говорит, что делать; текст блока рядом повторял бы его слово в слово. --}}
                    @php $about = $requirement ? null : $position->stage->managerText(); @endphp
                    @if ($about)<p class="mt-3 whitespace-pre-line text-ink-muted">{{ $about }}</p>@endif
                    @if ($payload->isNotEmpty())
                        <dl class="mt-5 grid grid-cols-1 gap-x-8 gap-y-3 sm:grid-cols-2">
                            @foreach ($payload as $k => $v)
                                <div class="min-w-0"><dt class="text-sm text-ink-dim">{{ collect($position->stage->staff_fields)->firstWhere('key', $k)['label'] ?? $k }}</dt><dd class="nums mt-0.5 break-words font-normal">{{ $v }}</dd></div>
                            @endforeach
                        </dl>
                    @endif

                    @if ($stepInvoices->isNotEmpty())
                        <div class="list mt-5">
                            {{-- Под счётом — его ссылка на оплату с «Отправить»: её не надо делать, она уже есть. --}}
                            @foreach ($stepInvoices as $i)@include('cabinet.deals.invoice-row', ['invoice' => $i])@unless ($i->isOwed())<x-billing.pay-status :invoice="$i"/>@endunless @endforeach
                        </div>
                    @endif
                    {{-- Просьба — продолжение той же карточки, без плашки в плашке. --}}
                    @if ($noInvoice)
                        <p class="mt-5 font-medium">Готовим счёт</p>
                    @elseif ($requirement)
                        <div class="mt-6">
                            <h3 class="text-lg">{{ $requirement->title }}</h3>
                            @if ($rejected)<p class="mt-2 font-medium text-urgent">Оплата <span class="nums">{{ \App\Support\Money::rub($rejected->amount) }}</span> от <span class="nums">{{ $rejected->paid_at->translatedFormat('j M') }}</span> не поступила{{ $rejected->reject_reason ? ': '.$rejected->reject_reason : '' }}</p>@endif
                            @if ($requirement->text)<p class="mt-2 whitespace-pre-line text-ink-muted">{{ $requirement->text }}</p>@endif
                            @if ($embedHandover)@include('cabinet.deals.handover', ['embedded' => true])@endif
                            {{-- Срок просьбы — тот же, что часы в шапке шага: второй раз его не пишем. --}}
                            @if ($requirement->due_at && ! ($position->deadline_at && abs($position->deadline_at->diffInMinutes($requirement->due_at)) < 1))
                                <p class="mt-2 text-sm {{ $requirement->due_at->isPast() ? 'text-urgent' : 'text-ink-muted' }}">до {{ $requirement->due_at->translatedFormat('j M, H:i') }}, <span class="nums font-medium" data-controller="timer" data-timer-until-value="{{ $requirement->due_at->toIso8601String() }}" data-timer-done-value="срок вышел"></span></p>
                            @endif

                            @if ($payStep && $unpaid->isNotEmpty())
                                <div class="mt-4 flex flex-wrap gap-2">
                                    @foreach ($unpaid as $i)<x-ui.button :href="'/account/money/deals/'.$deal->id" size="s">Оплатить{{ $unpaid->count() > 1 ? ' '.$i->label() : '' }}</x-ui.button>@endforeach
                                </div>
                            @elseif ($requirement->asks === Asks::Document)
                                <div class="mt-4" data-controller="photos" data-photos-url-value="/deals/{{ $deal->id }}/files">
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

                            @unless ($payStep && $unpaid->isNotEmpty())
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
                            @endunless
                        </div>
                    @endif
                </div>
            @else
                <x-ui.empty>Сделка пока не в работе. Мы напишем, когда что-то изменится</x-ui.empty>
            @endif

            @if ($handover && ! $embedHandover)@include('cabinet.deals.handover', ['embedded' => false])@endif

            {{-- В гараже свой путь машины — путь сделки там не повторяется. --}}
            @if (($ladder ?? true) && $blocks->isNotEmpty())
                <div class="box">
                    <h2 class="box-title">Путь сделки</h2>
                    <div class="mt-3"><x-route.timeline :blocks="$blocks" :current="$currentBlock" :steps="$steps" :waiting="$waiting" :deal="$deal"/></div>
                </div>
            @endif
</div>
