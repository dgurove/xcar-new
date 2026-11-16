{{-- Оплата счёта строкой `x-money.line`: способ иконкой, что и когда, у ссылки — чем заплатили; сотруднику ещё комиссия
     и зачислено ли на счёт. Заявка менеджера — оранжевым «ждёт подтверждения», отклонённая — зачёркнутой с причиной.
     slip — адрес платёжки: строкой файла под строкой, открывается шторкой документов. title и icon — у выплаты свои.
     Слот `acts` уходит строке как есть. --}}
@props(['payment', 'attempt' => null, 'staff' => false, 'slip' => null, 'title' => null, 'icon' => null])
@php
    use App\Billing\PaymentSource; use App\Billing\PaymentState; use App\Billing\Acquiring\PayMethod;
    $p = $payment; $a = $attempt; $day = \App\Support\Ago::day($p->paid_at);
    $file = $slip ? $p->slip() : null;
    [$ico, $head, $sub, $tone] = match (true) {
        $p->state === PaymentState::Rejected => [$p->source->icon(), 'Не поступила', $p->reject_reason ?: $day, 'danger'],
        $p->state === PaymentState::Claimed => [$p->source->icon(), $p->source === PaymentSource::Cash ? 'Наличные' : 'Оплата по счёту', ($p->source === PaymentSource::Cash ? 'отдали ' : 'сообщили ').$day.', ждёт подтверждения', 'urgent'],
        // Коротко — строка не разваливается на три (16.11.2026, владелец: «убогая вёрстка»): что засчитана — второй строкой.
        $p->source === PaymentSource::Credit => ['offset', 'Оплата по ссылке', $day.', засчитана', 'open'],
        // Вычел вознаграждение из счёта — «оставил себе», а не бухгалтерский «зачёт» (06.10.2026).
        $p->source === PaymentSource::Offset => ['offset', $staff ? 'Менеджер оставил себе вознаграждение' : 'Вы оставили себе вознаграждение', $day, 'muted'],
        $p->source === PaymentSource::Acquiring => [PayMethod::icon($a?->method), 'Оплата по ссылке', $day.' '.PayMethod::label($a?->method)
            .($staff && $a?->fee() > 0 ? ', комиссия '.\App\Support\Money::exact($a->fee()) : '')
            .($staff && $a ? ($a->payout ? ', на счёте с '.\App\Support\Ago::day($a->payout->booked_at) : ', ждёт зачисления') : ''), 'open'],
        $p->source === PaymentSource::Cash => ['cash', 'Наличные', $day, 'open'],
        default => ['bank', 'Оплата по счёту', $day.($p->refNumber() ? ', п/п '.$p->refNumber() : ''), 'open'],
    };
@endphp
<x-money.line :icon="$icon ?? $ico" :title="$title ?? $head" :sub="$sub" :amount="$p->amount" :tone="$tone" :strike="$p->state === PaymentState::Rejected" {{ $attributes }}>
    @if ($file || isset($acts))
        <x-slot:acts>
            @if ($file)
                {{-- Платёжка — строкой файла, как все документы (16.11.2026, владелец: чип «платёжка» — «это файл»). --}}
                <x-ui.file name="Платёжка" :mime="$file->mime_type" :bytes="$file->size" :size="\App\Support\Ago::at($file->created_at, true, words: false)" :href="$slip" class="w-full"/>
            @endif
            {{ $acts ?? '' }}
        </x-slot:acts>
    @endif
</x-money.line>
