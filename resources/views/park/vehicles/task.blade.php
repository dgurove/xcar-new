{{-- Задача сверху карточки строки (владелец 09.10.2026: «жмёшь заявку — „Связались“, а с кем связаться, непонятно»): шаг
     открытой заявки словами, письмо, по которому она заведена (у выдачи — письмо о продаже: x-mail.last-letter compact,
     «Вся переписка» и «Ответить»), контакт со звонком и действие. Звонок — исходы прямо здесь (steps/call, та же форма
     `/requests/{id}/contact`, что в деле); эвакуация в пути — «Выехали»; приём и выдача с осмотром — в дело, одной дорогой. --}}
@php
    use App\Park\{Delivery, RequestState, RequestType, VehicleState};
    $t = $task;
    $tow = $t->type === RequestType::Tow;
    $release = $t->type === RequestType::Release;
    // Звонок ещё не сделан — или не дозвонились и перезвонить (позвонить можно и раньше срока).
    $calling = ! $release && $t->state === RequestState::New && ($t->needsCall() || ($t->delivery === null && $t->next_call_at));
    // У выдачи — покупатель из письма о продаже (страхователь ему не нужен), иначе контакт самой заявки.
    $phone = $release ? ($vehicle->pickup_phone ?? $t->contact_phone) : ($t->contact_phone ?? $vehicle->contact_phone);
    $who = $release ? ($vehicle->pickup_name ?? $t->contact_name) : ($t->contact_name ?? $vehicle->contact_name);
    $when = $t->planned_at?->translatedFormat('j M, H:i');
    $title = match (true) {
        $calling && $t->next_call_at?->isFuture() => 'Позвонить снова в '.$t->next_call_at->translatedFormat($t->next_call_at->isToday() ? 'H:i' : 'j M, H:i'),
        $calling => 'Нужно позвонить',
        $tow && $t->state === RequestState::New => 'Нужно назначить эвакуатор',
        $tow && $t->state === RequestState::Scheduled => 'Эвакуатор назначен'.($when ? ' на '.$when : ''),
        $tow => 'Эвакуатор в пути',
        $release => $vehicle->state === VehicleState::Stored ? 'Нужно выдать' : 'Выдача после приёма',
        default => ($t->delivery === Delivery::Self ? 'Привезёт сам' : 'Ждём ТС').($when ? ' '.$when : ', дата не назначена'),
    };
    $facts = array_filter([
        $tow ? $t->from_address : null,
        $tow && $t->yard ? 'на '.$t->yard->name : null,
        $tow ? $t->carrier : null,
        $release && $vehicle->sold_at ? 'продана '.$vehicle->sold_at->translatedFormat('j M') : null,
    ]);
@endphp
<section class="mt-3 flex flex-col gap-3">
    <h3 class="text-base font-medium">{{ $title }}</h3>
    @if ($facts)<p class="text-sm text-ink-muted">{{ implode(', ', $facts) }}</p>@endif
    @if ($taskLetter)
        <x-mail.last-letter :message="$taskLetter" :count="$letterCount" :url="'/cars/'.$vehicle->id.'/letters'" compact/>
    @endif
    @if ($calling)
        @unless ($phone)<p class="text-sm text-urgent">В письме нет телефона</p>@endunless
        <form method="post" action="/requests/{{ $t->id }}/contact" data-controller="reveal" data-reveal-label-selector-value="#task-submit-{{ $t->id }}">
            @csrf
            @include('park.vehicles.steps.call', ['req' => $t, 'letterText' => null, 'submitId' => 'task-submit-'.$t->id])
        </form>
    @else
        @if ($phone)
            <a href="tel:+{{ preg_replace('/\D+/', '', $phone) }}" class="flex items-center gap-3 rounded-(--radius-l) bg-surface-2 px-4 py-3">
                <span class="btn btn-quiet btn-round shrink-0"><x-ui.icon name="phone" class="size-5"/></span>
                <span class="min-w-0"><span class="block nums">{{ $phone }}</span>@if ($who)<span class="block truncate text-sm text-ink-muted">{{ $who }}</span>@endif</span>
            </a>
        @elseif ($who)
            <p class="text-sm">{{ $who }}</p>
        @endif
        <div class="flex flex-wrap gap-2">
            @if ($tow && $t->state === RequestState::Scheduled)
                <form method="post" action="/requests/{{ $t->id }}/start" class="contents">@csrf<button class="btn btn-s btn-accent">Выехали</button></form>
            @elseif ($verb = $t->verb())
                <a href="/cars/{{ $vehicle->id }}?req={{ $t->id }}" class="btn btn-s btn-accent" data-turbo-frame="_top">{{ $verb }}</a>
            @endif
        </div>
    @endif
</section>
