{{-- Машина в гараже. Телефон: кадр, деньги одной плашкой, расходы; внизу кнопка того, что сейчас надо сделать
     (этап: «Привёз», «Готова», «Продаю»; расход), редкое — в «⋯». ПК: слева расходы (работа), справа кадр и деньги.
     Ждёт страховую — вместо расходов шаг сделки с её кнопками («Забираю», «Отказываюсь»), как на странице сделки.
     Шторки: расход (новый и каждый свой), «Продаю», «Продана», счёт с вознаграждением, «Поступило», «Оплатить». --}}
@php
    use App\Garage\CarState;
    use App\Garage\Payer;
    use App\Support\Money;
    use App\Support\Plural;
    $offer = $car->offer;
    $user = auth()->user();
    $staff = $user->isAdmin();
    $n = $offer->number;
    $photo = $offer->mainPhoto();
    $s = \App\Garage\Settlement::of($car);
    $invoice = $car->invoice;
    // Ждёт денег сейчас: выплата менеджеру (покупатель уже заплатил) или сам счёт.
    $current = $car->payoutInvoice ?? $invoice;
    $unpaid = $current && $current->remaining() > 0;
    $frozen = $car->isFrozen();
    $working = $car->state->isWorking();
    $canAdd = ! $frozen && $working || ($staff && ! $frozen && ! $car->isWaiting());
    $advance = $car->state->advance();
    $post = fn (string $label) => ['post', "/garage/cars/{$n}/advance", $label];
    // Кнопки внизу: главная — шаг этапа, рядом — расход; у сотрудника — итог продажи, счёт, оплата.
    $buttons = array_values(array_filter(match (true) {
        ! $staff && $car->state === CarState::Delivery => [$post('Привёз'), $canAdd ? ['emit', 'cost-new', 'Расход'] : null],
        ! $staff && $car->state === CarState::Repair => [['emit', 'cost-new', 'Записать расход'], $post('Готова')],
        ! $staff && $car->state === CarState::Selling => [['emit', 'selling', 'Продаю'], $canAdd ? ['emit', 'cost-new', 'Расход'] : null],
        ! $staff && $unpaid && ! $current->isOwed() && $car->invoice_to !== 'buyer' && $current->remaining() - $current->claimed() > 0 => [['emit', 'pay', 'Оплатить']],
        $staff && $working => [['emit', 'sold', 'Продана']],
        $staff && $car->state === CarState::Sold && ! $invoice => [['emit', 'settle', $car->manager ? 'Выставить счёт' : 'Закрыть расчёт']],
        $staff && $car->awaitsPayout() => [['emit', 'payout', 'Выплата менеджеру']],
        $staff && $unpaid => [['emit', 'paid', $current->isOwed() ? 'Выплатили' : 'Поступило']],
        default => [],
    }));
    $more = array_filter([
        $staff && $canAdd ? ['emit', 'cost-new', 'Записать расход'] : null,
        $staff && $advance ? ['form', 'POST', "/garage/cars/{$n}/advance", $advance[1], null] : null,
        $staff && $car->state === CarState::Sold && ! $invoice ? ['form', 'DELETE', "/garage/cars/{$n}/sold", 'Не продана', 'Снять итог продажи?'] : null,
        $staff && $unpaid ? ['form', 'DELETE', "/garage/cars/{$n}/invoice", 'Аннулировать', 'Аннулировать документ? Расходы и итог снова можно будет поправить'] : null,
        $staff && $working && $car->costs->isEmpty() ? ['form', 'DELETE', "/garage/cars/{$n}", 'Отдали по ошибке', 'Вернуть ТС в черновики?'] : null,
    ]);
    $stageDays = $car->stageDays();
    // Деньги плашкой — сотруднику всегда (вложено, прибыль), менеджеру — с продажи: до неё его расходы — итогом списка.
    $money = $staff ? ! $car->isWaiting() : $car->isSold();
    // Для пути: на каком шаге маршрута сделка и ждут ли ответа менеджера.
    $asks = ! $staff && ($step['requirement'] ?? null);
    $waitingBlock = $car->isWaiting() ? ($step['position'] ?? null)?->stage->block?->name ?? $offer->stage()?->block?->name : null;
@endphp
<x-ui.shell :title="$offer->titleWithYear()" :back="['Гараж', '/garage']">
    {{-- Под заголовком — состояние словом, дни и чья строкой; VIN копируется. Чипов нет: это не метки, а факты. --}}
    <div class="-mt-3 mb-5 flex flex-wrap items-center gap-x-3 gap-y-1.5 text-sm">
        <x-ui.state :tone="$car->state->tone()" class="text-sm">{{ mb_strtolower($car->state->label()) }}</x-ui.state>
        @if ($staff)<span class="text-ink-muted">{{ $car->manager?->shortName() ?? 'взяли под себя' }}</span>@endif
        @if ($staff && $car->deal_id && $car->isWaiting())<a href="{{ \App\Support\Surface::Crm->url('/work/deals/'.$car->deal_id) }}" class="text-accent-text" data-turbo="false">Сделка в CRM</a>@endif
        @if ($offer->vin)<span class="nums text-ink-muted"><x-ui.vin-code :vin="$offer->vin"/></span>@endif
    </div>
    @error('car')<x-ui.flash tone="danger" class="mb-4">{{ $message }}</x-ui.flash>@enderror

    {{-- Справа нечего ставить (ни кадра, ни денег) — одна колонка шириной списков, а не пустая правая. --}}
    <div @class(['grid gap-6 lg:items-start', 'lg:grid-cols-[minmax(0,1fr)_22rem]' => $photo || $money, 'max-w-[56rem]' => ! $photo && ! $money])>
        {{-- ПК: справа кадр и деньги, липкие. Телефон: кадр и деньги первыми — когда деньги есть, они и есть главное. --}}
        @if ($photo || $money)
        <aside class="flex flex-col gap-4 lg:sticky lg:top-24 lg:order-last">
            @if ($photo)
                <x-offer.photo :media="$photo" sizes="(min-width: 1024px) 22rem, 100vw" class="aspect-video w-full rounded-(--radius-l) object-cover lg:aspect-[4/3]"/>
            @endif
            @if ($money)@include('garage.cars.money')@endif
        </aside>
        @endif

        <section class="flex min-w-0 flex-col gap-6">
            {{-- Ждёт страховую — шаг сделки с её кнопками («Забираю в гараж», «Отказываюсь»), её путь не повторяем. --}}
            @if ($step && ! $staff)
                @include('cabinet.deals.step', $step + ['ladder' => false])
            @endif

            <div class="box">
                <h2 class="box-title">Путь</h2>
                <div class="mt-3">@include('garage.cars.path')</div>
            </div>

            @if ($car->costs->isNotEmpty())
                <section>
                    <h2 class="list-head lg:pt-0">Расходы<span class="nums ml-auto text-base font-normal text-ink-muted">{{ Money::exact($car->spent()) }}</span></h2>
                    <div class="list">
                        @foreach ($car->costs as $cost)
                            @php $mine = $cost->editableBy($user); @endphp
                            {{-- Своя шторка у каждой строки: у контроллера шторки ровно один диалог. --}}
                            <div @if ($mine) data-controller="sheet" @endif>
                                <{{ $mine ? 'button type=button data-action=sheet#open' : 'div' }} class="row w-full text-left">
                                    <span class="min-w-0 flex-1">
                                        <span class="block">{{ $cost->title }}</span>
                                        <span class="row-sub">{{ $cost->spent_at->translatedFormat('j F') }}@if ($cost->payer === Payer::Xcar), платили мы@endif</span>
                                    </span>
                                    <span class="nums shrink-0">{{ Money::exact($cost->amount) }}</span>
                                    @if ($mine)<x-ui.chevron/>@endif
                                </{{ $mine ? 'button' : 'div' }}>
                                @if ($mine)
                                    <x-ui.sheet id="cost-{{ $cost->id }}" title="Расход">
                                        <form method="post" action="/garage/costs/{{ $cost->id }}" class="flex flex-col gap-4">
                                            @csrf @method('put')
                                            @include('garage.cars.cost-fields', ['cost' => $cost])
                                            <x-ui.button type="submit" variant="primary" block>Сохранить</x-ui.button>
                                        </form>
                                        <form method="post" action="/garage/costs/{{ $cost->id }}" class="mt-2" data-turbo-confirm="Убрать расход?">
                                            @csrf @method('delete')
                                            <x-ui.button type="submit" variant="ghost" block>Убрать</x-ui.button>
                                        </form>
                                    </x-ui.sheet>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif
        </section>
    </div>

    @if ($buttons || $more)
        <x-ui.action-bar>
            @foreach ($buttons as $i => $b)
                @if ($b[0] === 'post')
                    <form method="post" action="{{ $b[1] }}" class="contents">@csrf<button type="submit" class="btn {{ $i ? 'btn-quiet' : 'btn-accent' }} min-w-0 flex-1">{{ $b[2] }}</button></form>
                @else
                    <button type="button" class="btn {{ $i ? 'btn-quiet' : 'btn-accent' }} min-w-0 flex-1" data-controller="emit" data-action="emit#send" data-emit-event-param="{{ $b[1] }}:open">
                        @if ($b[1] === 'cost-new')<x-ui.icon name="plus" class="size-5"/>@endif{{ $b[2] }}
                    </button>
                @endif
            @endforeach
            @if ($more)
                <span data-controller="sheet" class="contents">
                    <button type="button" class="btn btn-quiet btn-round btn-lg {{ $buttons ? '' : 'ml-auto' }}" data-action="sheet#open" aria-label="Ещё"><x-ui.icon name="more" class="size-6"/></button>
                    <x-ui.sheet id="car-more" title="{{ $offer->titleWithYear() }}">
                        {{-- Меню строками, как «···» в приложении: редкое действие — словом, опасное — красным, не стопка кнопок. --}}
                        <div class="list">
                            @foreach ($more as $item)
                                @if ($item[0] === 'emit')
                                    <button type="button" class="row w-full text-left" data-controller="emit" data-action="emit#send sheet#close" data-emit-event-param="{{ $item[1] }}:open">{{ $item[2] }}</button>
                                @else
                                    <form method="post" action="{{ $item[2] }}" @if ($item[4]) data-turbo-confirm="{{ $item[4] }}" @endif class="contents">@csrf @method($item[1])<button type="submit" class="row w-full text-left {{ $item[1] === 'DELETE' ? 'text-danger' : '' }}">{{ $item[3] }}</button></form>
                                @endif
                            @endforeach
                        </div>
                    </x-ui.sheet>
                </span>
            @endif
        </x-ui.action-bar>
    @endif

    @include('garage.cars.sheets')
</x-ui.shell>
