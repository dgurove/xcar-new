{{-- Получение автомобиля (`Offers\Handover`) внутри задачи сделки — как карточка «Найдено в Почте»: сверху «От поставщика»,
     ниже подложкой подписанные поля (где, адрес, когда, контакт), телефон — ссылкой. 11.10.2026, владелец: оформленное как
     часть интерфейса (значки, капсула телефона) «не выглядит как что-то, что пришло извне». Что делать — заголовок задачи
     («Заберите автомобиль»), здесь только сведения. «Автомобиль забрал» вывоза без шага продажи (Альфа) ставит сам шаг. --}}
@php
    /** @var \App\Offers\Handover $handover */
    $rows = array_filter([
        'Где' => $handover->where,
        'Адрес' => $handover->address,
        'Когда' => $handover->dateLabel(),
    ]);
@endphp
<section class="handover mt-4" aria-label="Сведения поставщика">
    <p class="handover-cap"><x-ui.icon name="mail" class="size-4"/>От поставщика</p>
    <dl class="handover-card">
        @foreach ($rows as $label => $value)
            <div class="handover-row"><dt>{{ $label }}</dt><dd @class(['nums' => $label === 'Когда'])>{{ $value }}</dd></div>
        @endforeach
        @if ($handover->name || $handover->phone)
            <div class="handover-row">
                <dt>Контакт</dt>
                <dd>
                    @if ($handover->name)<span class="block break-words">{{ $handover->name }}</span>@endif
                    @if ($handover->phone)<a href="tel:+{{ $handover->phone }}" class="handover-phone nums"><x-ui.icon name="phone" class="size-4"/>{{ $handover->phoneFormatted() }}</a>@endif
                    @if ($handover->phone2)<a href="tel:+{{ $handover->phone2 }}" class="handover-phone nums"><x-ui.icon name="phone" class="size-4"/>{{ $handover->phone2Formatted() }}</a>@endif
                </dd>
            </div>
        @endif
    </dl>
</section>
