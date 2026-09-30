{{-- Оплата счёта строкой `x-money.line`: способ иконкой, что и когда, у ссылки — чем заплатили; сотруднику ещё комиссия
     и зачислено ли на счёт. Заявка менеджера — оранжевым «ждёт подтверждения», отклонённая — зачёркнутой с причиной.
     slip — адрес платёжки: чипом под строкой, открывается шторкой документов. title и icon — у выплаты свои.
     Слот `acts` уходит строке как есть. --}}
@props(['payment', 'attempt' => null, 'staff' => false, 'slip' => null, 'title' => null, 'icon' => null])
@php
    use App\Billing\PaymentSource; use App\Billing\PaymentState; use App\Billing\Acquiring\PayMethod;
    $p = $payment; $a = $attempt; $day = $p->paid_at->translatedFormat('j M');
    $file = $slip ? $p->slip() : null;
    [$ico, $head, $sub, $tone] = match (true) {
        $p->state === PaymentState::Rejected => [$p->source->icon(), 'Не поступила', $p->reject_reason ?: $day, 'danger'],
        $p->state === PaymentState::Claimed => [$p->source->icon(), $p->source === PaymentSource::Cash ? 'Наличные' : 'Оплата по счёту', ($p->source === PaymentSource::Cash ? 'отдали ' : 'сообщили ').$day.', ждёт подтверждения', 'urgent'],
        $p->source === PaymentSource::Offset => ['offset', 'Зачёт вознаграждения', $day, 'muted'],
        $p->source === PaymentSource::Acquiring => [PayMethod::icon($a?->method), 'Оплата по ссылке', $day.' '.PayMethod::label($a?->method)
            .($staff && $a?->fee() > 0 ? ', комиссия '.\App\Support\Money::exact($a->fee()) : '')
            .($staff && $a ? ($a->payout ? ', на счёте с '.$a->payout->booked_at->translatedFormat('j M') : ', ждёт зачисления') : ''), 'open'],
        $p->source === PaymentSource::Cash => ['cash', 'Наличные', $day, 'open'],
        default => ['bank', 'Оплата по счёту', $day.($p->ref ? ', п/п '.$p->ref : ''), 'open'],
    };
@endphp
<x-money.line :icon="$icon ?? $ico" :title="$title ?? $head" :sub="$sub" :amount="$p->amount" :tone="$tone" :strike="$p->state === PaymentState::Rejected" {{ $attributes }}>
    @if ($file || isset($acts))
        <x-slot:acts>
            @if ($file)
                <x-ui.doc :doc="['url' => $slip, 'type' => \App\Support\Docs::type($file->mime_type, $file->file_name), 'name' => $file->file_name, 'label' => 'Платёжка']" class="chip"><x-ui.icon name="file" class="size-3.5"/>платёжка</x-ui.doc>
            @endif
            {{ $acts ?? '' }}
        </x-slot:acts>
    @endif
</x-money.line>
