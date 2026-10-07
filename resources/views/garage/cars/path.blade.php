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
        CarState::Ready => $car->dealOpen() ? 'ждём документы со страховой' : null,
        CarState::Repair, CarState::Selling => $days($car->stageDays()),
        CarState::Sold => match (true) {
            ! $invoice => $staff ? 'выставить счёт' : 'готовим счёт',
            // Сумма — крупно в деньгах: здесь только что и до когда.
            $unpaid && $current->isOwed() => $staff ? 'отдаём менеджеру' : 'выплата вам',
            $unpaid && $car->invoice_to === 'buyer' => 'ждём оплату покупателя',
            $unpaid => ($staff ? 'менеджер отдаёт' : 'оплата').' до '.$current->due_at->translatedFormat('j M'),
            default => null,
        },
        default => null,
    };
    // Весь путь менеджеру начинается со сделки со страховой: идёт — её блок текущим (ваш ход — оранжевым), закрыта — галочкой.
    $deal = $set === 'all' && ($withDeal ?? true) && $car->deal_id ? $car->deal : null;
@endphp
@unless ($bare)<div class="steps">@endunless
    @if ($deal)
        @php $open = $car->dealOpen(); @endphp
        <div class="step step--{{ $open ? ($asks ? 'ask' : 'current') : 'done' }}">
            <span class="step-dot">@unless ($open)<x-ui.icon name="check" class="size-3"/>@endunless</span>
            <div class="step-body">
                <div class="step-head">
                    <span class="step-title flex-1">Документы со страховой</span>
                    @if (! $open && $deal->closed_at)<span class="nums shrink-0 text-sm text-ink-dim">{{ $deal->closed_at->translatedFormat('j M') }}</span>@endif
                </div>
                @if ($open && ($dealBlock || $asks))<p class="step-hint">{{ trim(mb_strtolower($dealBlock ?? '').($asks ? ', ваш ход' : ''), ', ') }}</p>@endif
            </div>
        </div>
    @endif
    @foreach ($car->path($states) as $p)
        <div class="step step--{{ $p['status'] === 'current' && $p['state'] === CarState::Waiting && $where === 'забрать' ? 'ask' : $p['status'] }}">
            <span class="step-dot">@if ($p['status'] === 'done')<x-ui.icon name="check" class="size-3"/>@endif</span>
            <div class="step-body">
                <div class="step-head">
                    {{-- Пройденное ожидание — факт: машина приехала. --}}
                    <span class="step-title flex-1">{{ $p['state'] === CarState::Waiting && $p['status'] === 'done' ? 'Машина приехала' : $p['state']->label() }}</span>
                    @if ($p['status'] === 'done' && $p['at'])<span class="nums shrink-0 text-sm text-ink-dim">{{ $p['at']->translatedFormat('j M') }}</span>@endif
                    @if (in_array($p['state'], $back ?? [], true))
                        <form method="post" action="/garage/cars/{{ $n }}/stage" class="contents" data-turbo-confirm="Вернуть на «{{ $p['state']->label() }}»?">@csrf<input type="hidden" name="state" value="{{ $p['state']->value }}"><button class="btn btn-s btn-quiet shrink-0">Вернуть</button></form>
                    @endif
                </div>
                @if ($p['status'] === 'current' && $hint)<p class="step-hint">{{ $hint }}</p>@endif
                @if ($p['status'] === 'current' && ($buttonsHere ?? []))
                    <div class="mt-3">@include('garage.cars.actions', ['buttons' => $buttonsHere, 'more' => [], 'bar' => false])</div>
                @endif
            </div>
        </div>
    @endforeach
@unless ($bare)</div>@endunless
