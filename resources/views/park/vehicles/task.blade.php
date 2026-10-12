{{-- Задача сверху карточки строки: все письма ТС строками, как в «Почте» (x-mail.letters; письмо, по которому заявка
     заведена, у выдачи — письмо о продаже, — этапом; строка открывает окно на письме, «Ответить»), телефон страхователя.
     Самого приёма и выдачи в карточке нет (12.10.2026, владелец: «разграничить машину и действие»): кнопка подвала
     открывает окно действия (`x-park.act-window`), после него карточка к следующей заявке. --}}
@php
    use App\Park\{RequestType, VehicleState};
    $t = $task;
    $intake = $t->type === RequestType::Intake && $vehicle->state->isBefore();
    $release = $t->type === RequestType::Release && $vehicle->state === VehicleState::Stored;
    $phone = $t->contact_phone ?? $vehicle->contact_phone;
    $who = $t->contact_name ?? $vehicle->contact_name;
@endphp
<section class="mt-3 flex flex-col gap-3">
    @if ($t->type === RequestType::Release && ! $release)<p class="text-sm text-ink-muted">Выдача после приёма</p>@endif
    <x-mail.letters :messages="$messages" :url="'/cars/'.$vehicle->id.'/letters'" :stages="[$taskLetterId]"/>
    @if ($intake && $phone)
        <a href="tel:+{{ preg_replace('/\D+/', '', $phone) }}" class="flex items-center gap-3 rounded-(--radius-l) bg-surface-2 px-4 py-3">
            <span class="btn btn-quiet btn-round shrink-0"><x-ui.icon name="phone" class="size-5"/></span>
            <span class="min-w-0"><span class="block nums">{{ $phone }}</span>@if ($who)<span class="block truncate text-sm text-ink-muted">{{ $who }}</span>@endif</span>
        </a>
    @elseif ($intake && $who)
        <p class="text-sm">{{ $who }}</p>
    @endif
</section>
