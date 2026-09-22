@php
    use App\Offers\DealState;
    use App\Offers\CommissionState;
    use App\Workflow\Asks;
    $currentBlock = $position?->stage->block_id;
    // Шаг «оплатите счёт»: платёжка живёт у счёта в «Деньгах», а не у просьбы — кнопка ведёт туда.
    $payStep = $requirement && $exits->contains(fn ($x) => str_starts_with(mb_strtolower($x->label), 'платёжное поручение'));
    $unpaid = $invoices->filter(fn ($i) => ! $i->isOwed() && $i->state === \App\Billing\InvoiceState::Issued);
    $feeState = $deal->commissionState();
    $waiting = $position ? match ($position->stage->waits_for) {
        \App\Workflow\WaitsFor::Manager => 'Ваш ход', \App\Workflow\WaitsFor::Supplier => 'ждём поставщика', \App\Workflow\WaitsFor::Us => 'ждём нас', default => null,
    } : null;
@endphp
<x-ui.cabinet :title="$offer->titleWithYear()" :back="['Сделки', '/account/deals']">
    <div class="grid gap-6 lg:grid-cols-[1fr_18rem]" data-deal-offer="{{ $offer->number }}">
        <div class="min-w-0 space-y-6">
            @if ($deal->state !== DealState::Active)
                <div class="box">
                    <x-ui.pill :tone="$deal->state === DealState::Done ? 'open' : 'danger'">{{ $deal->state->label() }}</x-ui.pill>
                    @if ($deal->closed_at)<span class="nums ml-2 text-sm font-normal text-ink-muted">{{ $deal->closed_at->translatedFormat('j M Y, H:i') }}</span>@endif
                </div>
            @elseif ($position)
                {{-- Текущий этап — одной карточкой и первым. Просьба живёт внутри неё; ждут человека и срок вышел — карточка тревожная. --}}
                <div class="box {{ $requirement && $position->isOverdue() ? 'box-urgent' : '' }}">
                    <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                        <h2 class="text-xl">{{ $position->stage->block?->name ?? 'Идёт работа' }}</h2>
                        <x-route.clock :position="$position"/>
                    </div>
                    @php $about = $requirement ? $position->stage->block?->text : $position->stage->managerText(); @endphp
                    @if ($about)<p class="mt-3 whitespace-pre-line text-ink-muted">{{ $about }}</p>@endif
                    @if ($position->payload)
                        <dl class="mt-5 grid grid-cols-1 gap-x-8 gap-y-3 sm:grid-cols-2">
                            @foreach ($position->payload as $k => $v)
                                <div class="min-w-0"><dt class="text-sm text-ink-dim">{{ collect($position->stage->staff_fields)->firstWhere('key', $k)['label'] ?? $k }}</dt><dd class="nums mt-0.5 break-words font-normal">{{ $v }}</dd></div>
                            @endforeach
                        </dl>
                    @endif

                    @if ($invoices->isNotEmpty())
                        <div class="mt-5 flex flex-col gap-2">
                            @foreach ($invoices as $i)
                                <a href="/account/money/invoices/{{ $i->id }}" class="row !py-3">
                                    <x-ui.icon name="file" class="size-5 shrink-0 text-ink-muted"/>
                                    <span class="min-w-0 flex-1"><span class="whitespace-nowrap">Счёт {{ $i->label() }}</span>{{ $i->state === \App\Billing\InvoiceState::Issued ? ', до '.$i->due_at->translatedFormat('j M') : '' }}{{ $i->claimed() > 0 ? ', ждёт подтверждения' : '' }}</span>
                                    <x-billing.light :invoice="$i"/>
                                    <span class="nums font-semibold">{{ \App\Support\Money::rub($i->remaining() > 0 ? $i->remaining() : $i->total) }}</span>
                                </a>
                            @endforeach
                        </div>
                    @endif
                    @if ($requirement)
                        <div class="box-nested mt-5">
                            <h3 class="text-lg">{{ $requirement->title }}</h3>
                            @if ($requirement->text)<p class="mt-2 whitespace-pre-line text-ink-muted">{{ $requirement->text }}</p>@endif
                            @if ($requirement->due_at)
                                <p class="mt-2 text-sm {{ $requirement->due_at->isPast() ? 'text-urgent' : 'text-ink-muted' }}">до {{ $requirement->due_at->translatedFormat('j M, H:i') }}, <span class="nums font-medium" data-controller="timer" data-timer-until-value="{{ $requirement->due_at->toIso8601String() }}" data-timer-done-value="срок вышел"></span></p>
                            @endif

                            @if ($payStep && $unpaid->isNotEmpty())
                                <div class="mt-4 flex flex-wrap gap-2">
                                    @foreach ($unpaid as $i)<x-ui.button :href="'/account/money/invoices/'.$i->id" size="s">Сообщить об оплате{{ $unpaid->count() > 1 ? ' '.$i->label() : '' }}</x-ui.button>@endforeach
                                </div>
                            @elseif ($requirement->asks === Asks::Document)
                                <div class="mt-4" data-controller="photos" data-photos-url-value="/account/deals/{{ $deal->id }}/files">
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
                            <form method="post" action="/account/deals/{{ $deal->id }}/reply" class="mt-5 flex flex-col gap-4">
                                @csrf
                                @if ($requirement->asks === Asks::Fields)
                                    @foreach ($requirement->fields as $field)
                                        <x-route.field :field="$field" :name="'fields['.$field['key'].']'"/>
                                    @endforeach
                                @endif
                                @if ($errors->has('exit'))<p class="field-error">{{ $errors->first('exit') }}</p>@endif
                                <div class="flex flex-wrap gap-3">
                                    @foreach ($exits as $exit)
                                        <x-ui.button name="exit" :value="$exit->id" :variant="$loop->first ? 'primary' : 'secondary'" :data-turbo-confirm="$exit->confirm">{{ $exit->label }}</x-ui.button>
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

            @if ($blocks->isNotEmpty())
                <div class="box">
                    <h2 class="text-xl">Путь сделки</h2>
                    <div class="mt-5"><x-route.timeline :blocks="$blocks" :current="$currentBlock" :steps="$steps" :waiting="$waiting"/></div>
                </div>
            @endif

            @if ($deal->requirements->whereNotNull('done_at')->isNotEmpty())
                <div class="box">
                    <h2 class="text-xl">Ваши ответы</h2>
                    <div class="mt-4 space-y-2">
                        @foreach ($deal->requirements->whereNotNull('done_at') as $req)
                            @if (!empty($req->answer['exit']))
                                <div class="flex items-baseline justify-between gap-3 rounded-(--radius-l) bg-surface-2 px-4 py-3">
                                    <span class="min-w-0 break-words">{{ $req->title }} — «{{ $req->answer['exit'] }}»@if (!empty($req->answer['fields'])): {{ implode(', ', $req->answer['fields']) }}@endif</span>
                                    <span class="nums shrink-0 text-sm font-normal text-ink-dim">{{ $req->done_at->translatedFormat('d.m.Y') }}</span>
                                </div>
                            @endif
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        {{-- Правая колонка: машина, номер, сумма. Зеркало страницы оффера. --}}
        <aside class="lg:self-start">
            <div class="box overflow-hidden !p-0">
                <a href="/offers/{{ $offer->number }}" class="group block">
                    @if ($photo = $offer->mainPhoto())
                        <x-offer.photo :media="$photo" sizes="(min-width: 1024px) 320px, 100vw" class="aspect-[4/3] w-full object-cover transition-transform duration-500 group-hover:scale-105"/>
                    @endif
                    <div class="p-6">
                        <p class="nums text-[32px] font-bold leading-none">{{ \App\Support\Money::rub($deal->amount) }}</p>
                        <p class="mt-2 text-sm text-ink-muted group-hover:text-accent-text">{{ $offer->titleWithYear() }}</p>
                    </div>
                </a>
                @if ($feeState !== CommissionState::Hidden)
                    {{-- Вознаграждение открывается со счёта; до него менеджер видит только цену. --}}
                    <a href="/account/money/deals/{{ $deal->id }}" class="mx-6 mb-5 flex items-center gap-2 rounded-(--radius-m) bg-surface-2 px-4 py-3">
                        <span class="min-w-0 flex-1"><span class="block text-sm text-ink-dim">Агентское вознаграждение</span><span class="nums font-semibold">{{ \App\Support\Money::rub($deal->commission) }}</span></span>
                        <x-ui.pill :tone="$feeState->tone()" class="!min-h-0 !py-1 text-xs">{{ mb_strtolower($feeState->label()) }}</x-ui.pill>
                    </a>
                @endif
                <div class="px-6 pb-6">
                    <dl class="grid grid-cols-2 gap-x-6 gap-y-3">
                        @foreach (['Предложение' => $offer->number, 'Год' => $offer->year, 'VIN' => $offer->vinMasked(), 'Город' => $offer->settlement?->name] as $label => $value)
                            @if ($value)<div class="min-w-0"><dt class="text-sm text-ink-dim">{{ $label }}</dt><dd class="nums mt-0.5 break-words font-normal">@if ($label === 'VIN')<x-ui.vin-code :vin="$value" :copy="$offer->show_vin"/>@elseif ($label === 'Город')<x-ui.place>{{ $value }}</x-ui.place>@else{{ $value }}@endif</dd></div>@endif
                        @endforeach
                    </dl>
                </div>
            </div>
        </aside>
    </div>
</x-ui.cabinet>
