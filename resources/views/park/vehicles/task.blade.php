{{-- Задача сверху карточки строки: письмо, по которому заявка заведена (у выдачи — письмо о продаже: x-mail.last-letter
     compact, «Вся переписка» и «Ответить»), контакт со звонком и само дело.
     Приём — прямо здесь (владелец 10.10.2026: «в окошке заявки полный бардак… тут чисто приём и всё»; звонка, эвакуации и
     даты приезда больше нет): обязательные поля ТС, которые письмо не дало (марка и модель), и форма приёма
     (`steps/intake`: парковка, место, когда, ключи, документы, фото). «Принять» — `POST /requests/{id}/intake` во фрейм
     карточки: строка уходит, карточка переходит к следующей заявке. Выдача — «Нужно выдать» и «Выдать» в дело
     (`task-action`): там QR, долг и акт. --}}
@php
    use App\Park\{RequestType, VehicleState};
    $t = $task;
    $release = $t->type === RequestType::Release;
    $intake = $t->type === RequestType::Intake && $vehicle->state->isBefore();
    // У выдачи — покупатель из письма о продаже (страхователь ему не нужен), иначе контакт самой заявки.
    $phone = $release ? ($vehicle->pickup_phone ?? $t->contact_phone) : ($t->contact_phone ?? $vehicle->contact_phone);
    $who = $release ? ($vehicle->pickup_name ?? $t->contact_name) : ($t->contact_name ?? $vehicle->contact_name);
    $facts = array_filter([$release && $vehicle->sold_at ? 'продана '.$vehicle->sold_at->translatedFormat('j M') : null]);
    // Марку и модель приём требует (`VehicleFields`): письмо их не дало — поля тут же, остальные поля ТС — в деле.
    $gaps = $intake && auth()->user()->canManagePark() && (! $vehicle->brand_id || ! $vehicle->model_id);
@endphp
<section class="mt-3 flex flex-col gap-3">
    @if ($release)<h3 class="text-base font-medium">{{ $vehicle->state === VehicleState::Stored ? 'Нужно выдать' : 'Выдача после приёма' }}</h3>@endif
    @if ($facts)<p class="text-sm text-ink-muted">{{ implode(', ', $facts) }}</p>@endif
    @if ($taskLetter)
        <x-mail.last-letter :message="$taskLetter" :count="$letterCount" :url="'/cars/'.$vehicle->id.'/letters'" compact/>
    @endif
    @if ($phone)
        <a href="tel:+{{ preg_replace('/\D+/', '', $phone) }}" class="flex items-center gap-3 rounded-(--radius-l) bg-surface-2 px-4 py-3">
            <span class="btn btn-quiet btn-round shrink-0"><x-ui.icon name="phone" class="size-5"/></span>
            <span class="min-w-0"><span class="block nums">{{ $phone }}</span>@if ($who)<span class="block truncate text-sm text-ink-muted">{{ $who }}</span>@endif</span>
        </a>
    @elseif ($who)
        <p class="text-sm">{{ $who }}</p>
    @endif
    @if ($intake)
        <form method="post" action="/requests/{{ $t->id }}/intake" class="flex flex-col">
            @csrf
            @if ($gaps)
                <input type="hidden" name="vehicle_form" value="1">
                <div class="grid grid-cols-2 gap-3">
                    <x-ui.combobox name="brand_id" label="Марка" url="/reference/brands" create="/reference/brands" :value="old('brand_id', $vehicle->brand_id)" :text="$vehicle->brand?->name" resets="#cb-model_id" required/>
                    <x-ui.combobox name="model_id" label="Модель" url="/reference/models" create="/reference/models" depends="#cb-brand_id [name=brand_id]" :value="old('model_id', $vehicle->model_id)" :text="$vehicle->model?->name" required/>
                </div>
            @endif
            @include('park.vehicles.steps.intake', ['req' => $t])
        </form>
    @endif
</section>
