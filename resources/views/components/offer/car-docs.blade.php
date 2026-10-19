{{-- «ПТС и СТС» — документы ТС отдельной карточкой под «Транспортным средством» (19.10.2026, владелец: «блок ТС и так
     раздутый, ПТС отдельным»): госномер, (Э)ПТС, СТС, кузов и шасси — у машины, их ждёт ДКП. Текстом — коды с копированием,
     «Изменить / Готово» — поля (`edit_card_controller`), своя форма. Встают и сами из бумаг страховой (`DocumentFields`). --}}
@props(['offer'])
@php
    $car = $offer->car;
    $doc = $car?->pts ? (preg_match('/^\d{15}$/', preg_replace('/\s+/', '', $car->pts)) ? 'ЭПТС' : 'ПТС') : 'ПТС';
    $cells = array_filter(['Госномер' => $car?->plate, $doc => $car?->pts, 'СТС' => $car?->sts, 'Кузов №' => $car?->body_no, 'Шасси (рама) №' => $car?->chassis_no], 'filled');
    $form = 'car-docs-'.$offer->number;
    $editing = $errors->hasAny(['plate', 'pts', 'sts', 'body_no', 'chassis_no']);
@endphp
<x-ui.card title="ПТС и СТС" id="car-docs" class="scroll-mt-24" {{ $attributes }} data-controller="edit-card" :data-edit-card-form-value="$form" :data-edit-card-editing-value="$editing ? 'true' : null">
    <x-slot:actions><button type="button" class="edit-card-toggle" data-edit-card-target="button" data-action="edit-card#toggle">{{ $editing ? 'Готово' : ($cells ? 'Изменить' : 'Добавить') }}</button></x-slot:actions>
    <div data-edit-card-target="view" @if ($editing) hidden @endif>
        @if ($cells)
            <div class="grid grid-cols-2 gap-3 !gap-y-2.5 leading-snug @4xl:grid-cols-3">
                @foreach ($cells as $label => $value)
                    <div class="min-w-0"><div class="field-label">{{ $label }}</div><div class="mt-0.5"><x-ui.copy-code :value="$value"/></div></div>
                @endforeach
            </div>
        @endif
    </div>
    <form method="post" action="/offers/{{ $offer->number }}/car-docs" id="{{ $form }}" data-controller="save-bar" data-edit-card-target="edit" @unless ($editing) hidden @endunless>
        @csrf @method('put')
        <div class="grid grid-cols-2 gap-3 @4xl:grid-cols-3">
            <x-ui.field name="plate" label="Госномер" :value="$car?->plate" copy/>
            <x-ui.field name="pts" label="ПТС или ЭПТС" :value="$car?->pts" copy/>
            <x-ui.field name="sts" label="СТС" :value="$car?->sts" copy/>
            <x-ui.field name="body_no" label="Кузов №" :value="$car?->body_no"/>
            <x-ui.field name="chassis_no" label="Шасси (рама) №" :value="$car?->chassis_no"/>
        </div>
        <x-ui.save-bar page/>
    </form>
</x-ui.card>
