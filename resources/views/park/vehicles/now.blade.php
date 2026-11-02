{{-- «Ждут от нас» сверху карточки ТС (19.10.2026, владелец: «непонятно, что от тебя хотят в этом окне», «и в park тоже»)
     — тем же видом, что плашки «что сейчас» в CRM (`admin/offers/now-block`): что ждут и кнопки. Письмо без ответа (`asks`:
     ветка с `needs_reply_at`) — «Запросили осмотр» / «Запросили документы» / «Ждут ответа», кто и когда, их слова одной
     строкой; «Ответить» — окно писем на этом письме с редактором, «Ответ не нужен» — `answered` (как «Сделано» в деле).
     Подтвердить покупателя и указать парковку — в деле, ссылкой. Выдача и акты — главная кнопка подвала, здесь их нет. --}}
@php
    use App\Mail\Extraction\Intent;
    $href = '/cars/'.$vehicle->id;
    $words = fn ($m) => match ($m->intent) {
        Intent::Inspect->value => 'Запросили осмотр',
        Intent::Docs->value => 'Запросили документы',
        Intent::Bounce->value => 'Письмо не доставлено',
        default => 'Ждут ответа',
    };
    $steps = array_values(array_intersect(['buyer', 'yard'], $tasks ?? []));
@endphp
@if ($asks->isNotEmpty() || $steps)
    <div class="mt-3 flex flex-col gap-3">
        @foreach ($asks as $m)
            <section class="flex flex-col gap-3 rounded-(--radius-l) bg-surface-2 p-3">
                <div class="flex min-w-0 flex-col gap-0.5">
                    <h2 class="text-base font-medium">{{ $words($m) }}</h2>
                    <span class="text-sm text-ink-muted">{{ \App\Mail\Chains\NodeTitle::who($m) }}, <span class="nums">{{ \App\Support\Ago::html($m->date_at, short: true) }}</span></span>
                    @if ($text = trim($m->ownText()))<p class="truncate text-sm">{{ $text }}</p>@endif
                </div>
                <div class="flex gap-2">
                    {{-- Просят фото и документы принятой ТС — форма отчёта ответом на это письмо (29.10.2026: из пустого
                         «Ответить» ушло письмо без вложений, Альфа: «Письмо пришло без вложения»); текстом — рядом. --}}
                    @if ($vehicle->accepted_at && $m->intent === Intent::Docs->value)
                        <x-park.act-button :vehicle="$vehicle" :step="'report?parent='.$m->id" label="Дослать фото и документы" class="btn-s min-w-0 flex-1"/>
                        <x-mail.window-button :url="$href.'/letters?at='.$m->id.'&reply=1'" label="Текстом" icon="reply" class="btn-s btn-quiet"/>
                    @else
                        {{-- Не доставлено — переслать на верный адрес (редактор окна сам откроется пересылкой, `x-mail.reply-button`). --}}
                        <x-mail.window-button :url="$href.'/letters?at='.$m->id.'&reply=1'" :label="$m->intent === Intent::Bounce->value ? 'Переслать' : 'Ответить'" :icon="$m->intent === Intent::Bounce->value ? 'mail' : 'reply'" class="btn-s btn-accent min-w-0 flex-1"/>
                    @endif
                    @if (auth()->user()->canManagePark())
                        <form method="post" action="{{ $href }}/letters/{{ $m->id }}/done" class="flex">@csrf<button class="btn btn-s btn-quiet">Ответ не нужен</button></form>
                    @endif
                </div>
            </section>
        @endforeach
        @foreach ($steps as $key)
            @if ($key === 'yard' && $vehicle->state === \App\Park\VehicleState::Stored && ! $vehicle->yard_id)
                {{-- Парковка — здесь же, без захода в дело (02.11.2026, владелец: «проваливаться в дело и возвращаться по
                     десяткам тачек — бред»): парковка и фактический день приёма (подставлена дата письма, по которому ТС
                     принята, — его отправляют через несколько дней), «Указать» — и карточка к следующей ТС без парковки. --}}
                @include('park.vehicles.steps.yard', ['compact' => true])
            @else
                <section class="flex items-center gap-3 rounded-(--radius-l) bg-surface-2 p-3">
                    <h2 class="min-w-0 flex-1 text-base font-medium">{{ \App\Park\Tasks::LABELS[$key] }}</h2>
                    <a href="{{ $href }}" class="btn btn-s btn-accent shrink-0" data-turbo-frame="_top">Открыть дело</a>
                </section>
            @endif
        @endforeach
    </div>
@endif
