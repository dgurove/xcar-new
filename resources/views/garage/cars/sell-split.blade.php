{{-- Расчёт продажи гаражной Совкомбанка строками (12.11.2026): шторки «Продаю» и «Продана» (`$live` — пересчёт по ходу
     набора, `sell_split_controller`) и деньги гаража менеджера после продажи. Цифры — одна дверь `Deal::sellSplit`; строки
     стоят сразу, до цены — тусклое «—», шторка не растёт. Вознаграждение менеджеру — его прибыль, зелёным, как в «Расчёте»
     сделки (`cabinet.deals.money`); сотруднику — обычной строкой. `$dense` — строки высотой полей `.fields` (шторка CRM, где
     над ними поля). Обёртка `.list` — у того, кто включает. --}}
@php
    use App\Support\Money;
    $live ??= false;
    $dense ??= false;
    $split = $deal->sellSplit($sale ?? null);
    $paid = $deal->credited();
    $rest = $split['rest'] ?? null;
    $done = $rest !== null && abs($rest) < 1;
    $target = fn (string $name) => $live ? 'data-sell-split-target="'.$name.'"' : '';
    $row = $dense ? 'row !min-h-11 !py-2' : 'row';
@endphp
<div @class([$row, 'profit' => ! $staff])>
    <span @class(['min-w-0 flex-1', 'font-medium' => ! $staff])>{{ $staff ? 'Вознаграждение менеджеру' : 'Ваше вознаграждение' }}</span>
    <span @class(['nums', 'profit-sum' => ! $staff && $split, 'text-ink-dim' => ! $split]) {!! $target('reward') !!} data-sign="{{ $staff ? '' : '+' }}">{{ $split ? ($staff ? '' : '+').Money::rub($split['reward']) : '—' }}</span>
</div>
<div class="{{ $row }}"><span class="min-w-0 flex-1">К оплате XCar</span><span @class(['nums', 'text-ink-dim' => ! $split]) {!! $target('payable') !!}>{{ $split ? Money::rub($split['payable']) : '—' }}</span></div>
@if ($paid > 0)
    <div class="{{ $row }}"><span class="min-w-0 flex-1">Уже пришло</span><span class="nums">{{ Money::rub($paid) }}</span></div>
    <div class="{{ $row }}">
        <span @class(['min-w-0 flex-1', 'text-open' => $done]) {!! $target('restLabel') !!}>{{ $done ? 'Всё оплачено' : ($rest !== null && $rest < 0 ? 'Переплата' : 'Осталось оплатить') }}</span>
        <span @class(['nums', 'text-ink-dim' => $rest === null]) {!! $target('rest') !!}>{{ $rest === null ? '—' : ($done ? '' : Money::rub(abs($rest))) }}</span>
    </div>
@endif
