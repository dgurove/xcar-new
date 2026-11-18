{{-- Поступления выписки по сделке — верхом «Продажи» (18.11.2026, владелец: «подписывать в блоке „Продажа“ компактно, что есть
     такое-то поступление от такого-то числа»): строкой на платёж — значок банка, дата, кто перевёл, сумма. Деньги за машину
     (`Transaction::carMoney`: и «Вне системы»), до трёх; больше — «ещё N» ссылкой на выписку с поиском по VIN. --}}
{{-- `except` — имена покупателя: его поступления сказаны словом шапки блока (`Billing\PayWord`, 18.11.2026), строкой — только чужие
     плательщики. --}}
@props(['deal', 'except' => []])
@php
    use App\Support\Money;
    // Имя — набором слов без регистра: в выписке «МИНАКОВА МАРИНА ВАЛЕРЬЕВНА», у нас «Марина Валерьевна Минакова».
    $words = fn (?string $n) => collect(preg_split('/[\s"«»]+/u', mb_strtolower((string) $n)))->filter(fn ($w) => mb_strlen($w) > 1)->sort()->values()->all();
    $known = collect($except)->map($words)->filter()->all();
    $txs = \App\Billing\Bank\Transaction::where('deal_id', $deal->id)->where('direction', 'in')->carMoney()
        ->orderByDesc('booked_at')->orderByDesc('id')->get()
        ->reject(fn ($tx) => in_array($words($tx->payerName()), $known, true));
    $vin = $deal->offer?->car?->vin;
@endphp
@if ($txs->isNotEmpty())
    <div class="deal-arrivals mb-3 flex flex-col gap-1 px-3 text-sm">
        @foreach ($txs->take(3) as $tx)
            <div class="flex min-w-0 items-baseline gap-2">
                <x-ui.icon name="bank" class="size-4 shrink-0 self-center text-ink-muted"/>
                <span class="shrink-0 text-ink-muted">{{ $tx->booked_at->format('d.m') }}</span>
                <span class="min-w-0 flex-1 truncate" title="{{ $tx->purpose }}">{{ $tx->payerName() }}</span>
                <span class="nums shrink-0">{{ Money::rub($tx->amount) }}</span>
            </div>
        @endforeach
        @if ($txs->count() > 3)
            <a href="/settings/bank/statement?q={{ urlencode($vin ?? '') }}" class="self-start text-accent-text" data-turbo-frame="_top">ещё {{ $txs->count() - 3 }}</a>
        @endif
    </div>
@endif
