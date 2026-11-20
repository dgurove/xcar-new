{{-- Путь машины в гараже, как трекинг посылки (`.steps`, тот же вид, что путь сделки): пройденные этапы галочкой с
     датой, текущий крупнее с тем, что сейчас происходит, впереди серым. Набор (`$set`): `prep` — дорожка «Гараж»
     (ждёт машину → подготовка → готова), `sale` — хвост дорожки «Продажа» (в продаже → продана → расчёт), `all` — весь
     путь одной лесенкой для менеджера на сайте: сделка со страховой, где машина, подготовка, продажа. Шаги без обёртки
     `.steps` при `$bare` — их вставляют в путь маршрута. Сотруднику пройденные этапы работы с машиной — с «Вернуть»
     (`GarageView` → `back`, `MoveCar`). --}}
@php
    use App\Garage\CarState;
    use App\Offers\PickupState;
    use App\Support\Plural;
    $set ??= 'all';
    $bare ??= false;
    $days = fn (int $d) => $d.' '.Plural::of($d, ['день', 'дня', 'дней']);
    $states = match ($set) { 'prep' => CarState::prep(), 'sale' => CarState::sale(), default => CarState::cases() };
    [$where] = PickupState::of($offer, $user);
    $hint = match ($car->state) {
        CarState::Waiting => $where,
        CarState::Ready => $car->papersPending() ? 'ждём документы со страховой' : null,
        CarState::Repair, CarState::Selling => $days($car->stageDays()),
        // Совкомбанк: счёт ПРАЙМ — у сделки (покупателю), своего расчёта у машины нет.
        CarState::Sold => $car->settledByDeal() ? match ($car->deal->invoiceGap()) {
            'buyer' => $staff ? 'менеджер указывает покупателя' : 'укажите покупателя',
            'passport' => $staff ? 'менеджер прикладывает паспорт покупателя' : 'приложите паспорт покупателя',
            'commission' => $staff ? 'посчитать вознаграждение' : 'готовим счёт',
            null => $car->deal->issuedInvoices()->where('state', \App\Billing\InvoiceState::Issued)->exists() ? 'ждём оплату покупателя' : null,
            default => $staff ? 'выставить счёт' : 'готовим счёт',
        } : match (true) {
            ! $invoice => $staff ? 'выставить счёт' : 'готовим счёт',
            // Сумма — крупно в деньгах: здесь только что и до когда.
            $unpaid && $car->invoice_to === 'buyer' => 'ждём оплату покупателя',
            // Срок — счётчиком, не датой (17.11.2026, владелец): «оплата, осталось 2 д 3 ч» / «просрочено 1 д 4 ч».
            $unpaid => ($staff ? 'менеджер отдаёт' : 'оплата').($current->due_at ? ', '.($current->due_at->isPast() ? \App\Support\Ago::overdue($current->due_at) : \App\Support\Ago::left($current->due_at)) : ''),
            default => null,
        },
        default => null,
    };
    // Весь путь менеджеру начинается со сделки со страховой: идёт — её блок текущим (ваш ход — оранжевым), закрыта — галочкой.
    $deal = $set === 'all' && ($withDeal ?? true) && $car->deal_id ? $car->deal : null;
@endphp
@php
    $steps = $car->path($states);
    // Покупка идёт, а забирать ещё рано (Альфа — только после оплаты, 20.11.2026, владелец: «почему этап „Вывоз“, если он ещё не
    // оплатил»): текущей — одна «Покупка», этапы машины впереди серым. Где вывоз до оплаты разрешён (Совкомбанк), — как было.
    if ($deal && $car->dealOpen() && ! $deal->mayPickUp()) {
        $steps = array_map(fn ($p) => $p['status'] === 'current' ? ['status' => 'next'] + $p : $p, $steps);
    }
@endphp
@unless ($bare)<div class="steps">@endunless
    @if ($deal)
        @php $open = $car->dealOpen(); @endphp
        <div class="step step--{{ $open ? ($asks ? 'ask' : 'current') : 'done' }}">
            <span class="step-dot">@unless ($open)<x-ui.icon name="check" class="size-3"/>@endunless</span>
            <div class="step-body">
                <div class="step-head">
                    {{-- «Покупка», а не «Документы со страховой» (18.11.2026, владелец: «максимально непонятная формулировка»; «не у страховой —
                         по-разному бывает»). Что сейчас — шапкой шага выше, подстроки с блоком маршрута нет. --}}
                    <span class="step-title flex-1">Покупка</span>
                    @if (! $open && $deal->closed_at)<span class="nums shrink-0 text-sm text-ink-dim">{{ \App\Support\Ago::html($deal->closed_at, words: false) }}</span>@endif
                </div>
                @if ($open && $staff && $dealBlock)<p class="step-hint">{{ mb_strtolower($dealBlock) }}</p>@endif
            </div>
        </div>
    @endif
    @foreach ($steps as $p)
        <div class="step step--{{ $p['status'] === 'current' && $p['state'] === CarState::Waiting && (str_starts_with($where, 'забрать') || $where === 'позвонить владельцу') ? 'ask' : $p['status'] }}">
            <span class="step-dot">@if ($p['status'] === 'done')<x-ui.icon name="check" class="size-3"/>@endif</span>
            <div class="step-body">
                <div class="step-head">
                    <span class="step-title flex-1">{{ $p['state']->label() }}</span>
                    @if ($p['status'] === 'done' && $p['at'])<span class="nums shrink-0 text-sm text-ink-dim">{{ \App\Support\Ago::html($p['at'], words: false) }}</span>
                    {{-- Менеджеру у текущего этапа — сколько идёт, справа, вместо подписи под ним (18.11.2026). --}}
                    @elseif ($p['status'] === 'current' && ! $staff && $p['state'] !== CarState::Sold)<span class="nums shrink-0 text-sm text-ink-dim">{{ max(1, $car->stageDays()) }}д</span>@endif
                    @if (in_array($p['state'], $back ?? [], true))
                        <form method="post" action="/garage/cars/{{ $n }}/stage" class="contents" data-turbo-confirm="Вернуть на «{{ $p['state']->label() }}»?">@csrf<input type="hidden" name="state" value="{{ $p['state']->value }}"><button class="btn btn-s btn-quiet shrink-0">Вернуть</button></form>
                    @endif
                </div>
                {{-- Подсказка под этапом — только сотруднику; менеджеру его дело — шапкой страницы (`Turn::ofGarage`), без описаний. --}}
                @if ($p['status'] === 'current' && $hint && $staff)<p class="step-hint">{{ $hint }}</p>@endif
                @if ($p['status'] === 'current' && ($buttonsHere ?? []))
                    <div class="mt-3">@include('garage.cars.actions', ['buttons' => $buttonsHere, 'more' => [], 'bar' => false])</div>
                @endif
            </div>
        </div>
    @endforeach
@unless ($bare)</div>@endunless
