{{-- Попытки плательщика по ссылке, свежие сверху: шторка ссылки и её карточка в «Оплатах» (там и у оплаченной). --}}
@props(['link', 'limit' => 5])
@php use App\Billing\Acquiring\PayMethod; @endphp
@if ($link->attempts->isNotEmpty())
    <section>
        <div class="list-cap">Плательщик открывал оплату</div>
        <div class="list">
            @foreach ($link->attempts->reverse()->take($limit) as $a)
                @php [$aText, $aTone] = match (true) { $a->succeeded() => ['оплачено', 'open'], $a->isPending() => ['не завершил', 'urgent'], $a->cancel_reason === 'expired_on_confirmation' => ['бросил', 'muted'], default => [$a->cancelLabel() ?? 'отклонено', 'danger'] }; @endphp
                <x-money.line :icon="PayMethod::icon($a->method)" :title="\App\Support\Ago::day($a->created_at, true)" :sub="$a->method ? PayMethod::label($a->method) : null" :tone="$aTone">
                    <x-slot:acts><x-ui.state :tone="$aTone">{{ $aText }}</x-ui.state></x-slot:acts>
                </x-money.line>
            @endforeach
        </div>
    </section>
@endif
