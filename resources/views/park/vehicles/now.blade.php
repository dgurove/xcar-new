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
                    <span class="text-sm text-ink-muted">{{ \App\Mail\Chains\NodeTitle::who($m) }}, <span class="nums">{{ \App\Support\Ago::stamp($m->date_at) }}</span></span>
                    @if ($text = trim($m->ownText()))<p class="truncate text-sm">{{ $text }}</p>@endif
                </div>
                <div class="flex gap-2">
                    <x-mail.window-button :url="$href.'/letters?at='.$m->id.'&reply=1'" label="Ответить" icon="reply" class="btn-s btn-accent min-w-0 flex-1"/>
                    @if (auth()->user()->canManagePark())
                        <form method="post" action="{{ $href }}/letters/{{ $m->id }}/done" class="flex">@csrf<button class="btn btn-s btn-quiet">Ответ не нужен</button></form>
                    @endif
                </div>
            </section>
        @endforeach
        @foreach ($steps as $key)
            <section class="flex items-center gap-3 rounded-(--radius-l) bg-surface-2 p-3">
                <h2 class="min-w-0 flex-1 text-base font-medium">{{ \App\Park\Tasks::LABELS[$key] }}</h2>
                <a href="{{ $href }}" class="btn btn-s btn-accent shrink-0" data-turbo-frame="_top">Открыть дело</a>
            </section>
        @endforeach
    </div>
@endif
