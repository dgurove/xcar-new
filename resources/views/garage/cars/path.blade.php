{{-- Путь машины в гараже, как трекинг посылки (`.steps`, тот же вид, что путь сделки): пройденные этапы галочкой с
     датой, текущий крупнее с тем, что сейчас происходит, впереди серым. Начинается с этапа, с которого машина пришла. --}}
@php
    use App\Garage\CarState;
    use App\Support\Money;
    use App\Support\Plural;
    $days = fn (int $d) => $d.' '.Plural::of($d, ['день', 'дня', 'дней']);
    $hint = match ($car->state) {
        CarState::Waiting => trim(mb_strtolower($waitingBlock ?? '').($asks ? ', ваш ход' : ''), ', ') ?: null,
        CarState::Delivery, CarState::Repair, CarState::Selling => $days($car->stageDays()),
        CarState::Sold => match (true) {
            ! $invoice => $staff ? 'выставить счёт' : 'готовим счёт',
            $unpaid && $current->isOwed() => ($staff ? 'отдаём менеджеру ' : 'вам к выплате ').Money::exact($current->remaining()),
            $unpaid && $car->invoice_to === 'buyer' => 'ждём оплату покупателя',
            $unpaid => ($staff ? 'менеджер отдаёт ' : 'отдать нам ').Money::exact($current->remaining()).' до '.$current->due_at->translatedFormat('j M'),
            default => null,
        },
        default => null,
    };
@endphp
<div class="steps">
    @foreach ($car->path() as $p)
        <div class="step step--{{ $p['status'] === 'current' && $asks ? 'ask' : $p['status'] }}">
            <span class="step-dot">@if ($p['status'] === 'done')<x-ui.icon name="check" class="size-3"/>@endif</span>
            <div class="step-body">
                <div class="step-head">
                    <span class="step-title min-w-0 flex-1">{{ $p['state']->label() }}</span>
                    @if ($p['status'] === 'done' && $p['at'])<span class="nums shrink-0 text-sm text-ink-dim">{{ $p['at']->translatedFormat('j M') }}</span>@endif
                </div>
                @if ($p['status'] === 'current' && $hint)<p class="step-hint">{{ $hint }}</p>@endif
            </div>
        </div>
    @endforeach
</div>
