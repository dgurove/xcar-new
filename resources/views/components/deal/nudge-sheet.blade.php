{{-- «Напомнить менеджеру» (19.11.2026, владелец: «по клику окно всплывает, что в последний раз мы ему про счёт писали такого-то числа
     во столько-то, типа уверены? и три кнопки: Да, напомнить сейчас / Да, напомнить через… (потом выбор через сколько дней) / Не
     напоминать»). Кнопка серая под счётом рядом с «Оплата получена»; шторка — `NudgeInvoice`. «Через…» — чипы дней, напоминание
     уйдёт в 10:00 по Москве. Запланировано — словом в шторке, «Не напоминать» его снимает. --}}
{{-- label / small — кнопкой в строке «Оплат» (22.11.2026): «Напомнить», маленькая. --}}
@props(['deal', 'invoice', 'label' => 'Напомнить менеджеру', 'small' => false])
@php
    $id = 'nudge-'.$invoice->id;
    $last = \App\Billing\Actions\NudgeInvoice::lastAt($invoice);
    $days = $last ? (int) floor($last->diffInDays(now())) : null;
    // Время — только когда оно есть (отправка, напоминание); у счёта, что сразу оплачивали, — один день выставления.
    $timed = $invoice->shown_at !== null || $invoice->nudged_at !== null;
    $action = '/work/deals/'.$deal->id.'/invoices/'.$invoice->id.'/nudge';
@endphp
<div class="contents" data-controller="sheet">
    <button type="button" {{ $attributes->class(['btn btn-quiet gap-2', 'btn-s' => ! $small, 'btn-xs' => $small]) }} data-action="sheet#open" aria-controls="{{ $id }}">{{ $label }}</button>
    <x-ui.sheet :id="$id" title="Напомнить менеджеру?">
        <div class="flex flex-col gap-4">
            @if ($last)
                <p>Последний раз писали менеджеру про {{ $invoice->isDocument() ? 'счёт' : 'ссылку на оплату' }} {!! \App\Support\Ago::html($last, $timed, words: false) !!}@if ($days >= 1), прошло {{ $days }} {{ \App\Support\Plural::of($days, ['день', 'дня', 'дней']) }}@endif</p>
            @endif
            @if ($invoice->nudge_at)<p class="text-ink-muted">Напомним {!! \App\Support\Ago::html($invoice->nudge_at, true, words: false) !!}</p>@endif
            <form method="post" action="{{ $action }}" class="contents">
                @csrf<input type="hidden" name="when" value="now">
                <x-ui.button type="submit" class="w-full">Да, напомнить сейчас</x-ui.button>
            </form>
            <details class="nudge-later">
                <summary class="btn btn-quiet w-full">Да, напомнить через…</summary>
                <form method="post" action="{{ $action }}" class="mt-3 flex flex-wrap gap-2">
                    @csrf<input type="hidden" name="when" value="days">
                    @foreach ([1 => '1 день', 2 => '2 дня', 3 => '3 дня', 5 => '5 дней', 7 => 'неделю'] as $n => $word)
                        <button type="submit" name="days" value="{{ $n }}" class="chip">через {{ $word }}</button>
                    @endforeach
                </form>
            </details>
            @if ($invoice->nudge_at)
                <form method="post" action="{{ $action }}" class="contents">
                    @csrf<input type="hidden" name="when" value="cancel">
                    <button type="submit" class="btn btn-ghost w-full">Не напоминать</button>
                </form>
            @else
                <button type="button" class="btn btn-ghost w-full" data-action="sheet#close">Не напоминать</button>
            @endif
        </div>
    </x-ui.sheet>
</div>
