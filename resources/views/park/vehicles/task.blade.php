{{-- Задача сверху карточки строки: письмо, по которому заявка заведена (у выдачи — письмо о продаже: x-mail.last-letter
     compact, «Вся переписка» и «Ответить») и само дело формой — прямо здесь, без перехода в дело (владелец 10.10.2026: «в
     окошке заявки полный бардак… тут чисто приём и всё», потом «пройдись так по другим этапам»).
     Приём: телефон страхователя, обязательные поля ТС, которых письмо не дало (марка и модель), форма `steps/intake`
     (парковка, место, когда, ключи, документы, фото), под ней «Отменить заявку». Выдача стоящей ТС: форма `steps/release` (QR, кто заберёт, когда,
     долг и наличные, фото, «Покупатель отказался»), шторки — `release-sheets`. Кнопка шлёт форму во фрейм карточки:
     строка уходит, карточка переходит к следующей заявке (`detail-advance`). Отчёт вендору ждёт шагом в деле. --}}
@php
    use App\Park\{RequestType, VehicleState};
    $t = $task;
    $intake = $t->type === RequestType::Intake && $vehicle->state->isBefore();
    $release = $t->type === RequestType::Release && $vehicle->state === VehicleState::Stored;
    $phone = $t->contact_phone ?? $vehicle->contact_phone;
    $who = $t->contact_name ?? $vehicle->contact_name;
    $canManage = auth()->user()->canManagePark();
    // Марку и модель приём требует (`VehicleFields`): письмо их не дало — поля тут же, остальные поля ТС — в деле.
    $gaps = $intake && $canManage && (! $vehicle->brand_id || ! $vehicle->model_id);
@endphp
<section class="mt-3 flex flex-col gap-3">
    @if ($t->type === RequestType::Release && ! $release)<p class="text-sm text-ink-muted">Выдача после приёма</p>@endif
    @if ($taskLetter)
        <x-mail.last-letter :message="$taskLetter" :count="$letterCount" :url="'/cars/'.$vehicle->id.'/letters'" compact/>
    @endif
    @if ($intake && $phone)
        <a href="tel:+{{ preg_replace('/\D+/', '', $phone) }}" class="flex items-center gap-3 rounded-(--radius-l) bg-surface-2 px-4 py-3">
            <span class="btn btn-quiet btn-round shrink-0"><x-ui.icon name="phone" class="size-5"/></span>
            <span class="min-w-0"><span class="block nums">{{ $phone }}</span>@if ($who)<span class="block truncate text-sm text-ink-muted">{{ $who }}</span>@endif</span>
        </a>
    @elseif ($intake && $who)
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
        {{-- Машину не привезут или письмо не заявка — отмена тут же, как «⋯ → Отменить заявку» в деле (`UnwindVehicle`):
             ТС и заявка исчезают, письма снова ждут в «Из писем». Уходит со списка целиком — строки больше нет. --}}
        @if ($canManage)
            <form method="post" action="/cars/{{ $vehicle->id }}" class="-mt-1" data-turbo-frame="_top" data-turbo-confirm="{{ $t->thread_id || $vehicle->threads_count ? 'Отменить заявку? ТС исчезнет, письма вернутся в «Из писем»' : 'Отменить заявку? ТС и заявка исчезнут' }}">
                @csrf @method('delete')
                <button class="btn btn-s btn-quiet w-full sm:w-auto">Отменить заявку</button>
            </form>
        @endif
    @elseif ($release)
        <form method="post" action="/requests/{{ $t->id }}/release" id="release-form-{{ $t->id }}" class="flex flex-col">
            @csrf
            @include('park.vehicles.steps.release', ['req' => $t, 'canManage' => $canManage])
        </form>
        @include('park.vehicles.release-sheets', ['req' => $t, 'canManage' => $canManage, 'formId' => 'release-form-'.$t->id])
    @endif
</section>
