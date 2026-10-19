{{-- Задача для исполнителя по ссылке, без входа (18.10.2026, владелец: «страница по ссылке — сразу»): машина и коды, откуда
     с «Маршрут» и звонком, когда, куда и кто принимает, своя оплата; ход лесенкой; ниже — форма текущего шага: созвон
     («Не дозвонился» рядом), «Получил документы», «Забрал» (кадры, ключи, куда), «Привёз». После «Привёз» — только смотреть.
     Логист и XCar видят всё в приложении: действует от имени исполнителя задачи (`TripController`). --}}
@php
    use App\Logistics\TransportState as S;
    use App\Support\Money;
    use App\Support\Phone;
    $base = '/trip/'.$code;
    $car = $t->car;
    $phone = Phone::normalize($t->ownerPhone());
    $from = $t->pickup_place ?? $t->fromAddress();
    $city = $t->city();
    $map = fn (?string $a) => $a ? 'https://yandex.ru/maps/?text='.rawurlencode($city && ! str_contains($a, $city) ? "{$city}, {$a}" : $a) : null;
    $to = $t->routeLabel();
    $blocked = in_array($t->state, [S::Assigned, S::Agreed], true) ? $t->pickupBlocker($by) : null;
    // Фото и документы машины, что исполнителю открыли глазом (`Logistics\FileAccess`, 19.10.2026).
    $files = \App\Logistics\FileAccess::files($t);
    $carPhotos = $files['photos']->filter(fn ($m) => \App\Logistics\FileAccess::forExecutor($t, $m))->values();
    $carPapers = $files['papers']->filter(fn ($m) => \App\Logistics\FileAccess::forExecutor($t, $m))->values();
    $step = match (true) {
        $t->state === S::Assigned && $t->isPickup() => 'call',
        $t->awaitsDocs() && $t->docs_sent_at && in_array($t->state, [S::Assigned, S::Agreed], true) => 'docs',
        in_array($t->state, [S::Assigned, S::Agreed], true) && ! $blocked => 'pickup',
        $t->state === S::Moving => 'deliver',
        default => null,
    };
@endphp
<x-pickup.layout :title="$t->title()" :home="false">
    <p class="text-sm text-ink-muted">Вывоз для {{ $by->shortName() }}</p>
    <h1 class="mt-1 text-2xl">{{ $t->title() }}</h1>
    <div class="mt-2 flex flex-wrap items-center gap-2">
        @if ($car?->plate)<x-ui.copy-code :value="$car->plate" class="tag" done="Госномер в буфере"/>@endif
        @if ($car?->vin)<x-ui.vin-code :vin="$car->vin" class="tag"/>@endif
        <x-car.keys :count="$car?->keys_count" label/>
        <span class="text-sm {{ $t->isLate() ? 'text-danger' : $t->state->tone() }}">{{ $t->isLate() ? 'не вывезли в срок' : $t->next() }}</span>
    </div>

    <div class="list mt-5">
        @if ($from)
            <a href="{{ $map($from) }}" target="_blank" rel="noopener" class="row items-start justify-between gap-4">
                <span class="min-w-0"><span class="block text-sm text-ink-muted">Откуда</span><span class="block whitespace-pre-line break-words">{{ $from }}</span></span>
                <span class="shrink-0 text-sm text-accent-text">Маршрут</span>
            </a>
        @endif
        @if ($t->ownerName() || $phone || $t->ownerPhone2())
            <div class="row items-center justify-between gap-4">
                <span class="min-w-0"><span class="block text-sm text-ink-muted">У кого забрать</span><span class="block break-words">{{ $t->ownerName() ?? 'Имени нет' }}</span></span>
                <span class="flex shrink-0 flex-col items-end gap-1.5">@if ($phone)<a href="tel:+{{ $phone }}" class="btn btn-s btn-accent shrink-0 nums"><x-ui.icon name="phone" class="size-4"/>{{ Phone::format($phone) }}</a>@endif
                    @if ($t->ownerPhone2())<a href="tel:+{{ $t->ownerPhone2() }}" class="btn btn-s btn-quiet shrink-0 nums"><x-ui.icon name="phone" class="size-4"/>{{ Phone::format($t->ownerPhone2()) }}</a>@endif</span>
            </div>
        @endif
        @if ($t->pickup_at)
            <div class="row justify-between gap-4"><span class="text-ink-muted">Когда</span><span class="nums">{{ $t->pickup_at->translatedFormat($t->pickup_at->format('H:i') === '00:00' ? 'j F' : 'j F, H:i') }}</span></div>
        @endif
        @if ($to || $t->reach)
            <a @if ($t->isOrder() || $t->destination?->value === 'other') href="{{ $map($t->to_address) }}" target="_blank" rel="noopener" @endif class="row items-start justify-between gap-4">
                <span class="min-w-0"><span class="block text-sm text-ink-muted">Куда</span><span class="block break-words {{ $to ? '' : 'text-urgent' }}">{{ $to ?? 'пока неизвестно' }}</span>@if ($t->reach)<span class="block text-sm text-ink-muted">{{ mb_lcfirst($t->reach->label()) }}</span>@endif @if ($t->to_contact)<span class="block text-sm text-ink-muted">принимает {{ $t->to_contact }}</span>@endif</span>
                @if ($t->isOrder() || $t->destination?->value === 'other')<span class="shrink-0 text-sm text-accent-text">Маршрут</span>@endif
            </a>
            @if ($t->to_phone)
                <a href="tel:+{{ $t->to_phone }}" class="row justify-between gap-4"><span class="text-ink-muted">Телефон на месте</span><span class="nums text-accent-text">{{ Phone::format($t->to_phone) }}</span></a>
            @endif
        @endif
        @if ($t->executor_cost)
            <div class="row justify-between gap-4"><span class="text-ink-muted">Оплата</span><span class="nums">{{ Money::rub((int) $t->executor_cost) }}</span></div>
        @endif
        @if ($t->comment || $t->call_note)
            <div class="row"><span class="min-w-0 whitespace-pre-line break-words">{{ implode("\n", array_filter([$t->comment, $t->call_note])) }}</span></div>
        @endif
        @if ($t->docs_track)
            <div class="row items-center justify-between gap-4">
                <span class="min-w-0"><span class="block text-sm text-ink-muted">Документы СДЭКом</span><x-ui.copy-code :value="$t->docs_track" class="tag mt-1" done="Трек в буфере"/></span>
                <a href="https://www.cdek.ru/ru/tracking?order_id={{ urlencode($t->docs_track) }}" target="_blank" rel="noopener" class="shrink-0 text-sm text-accent-text">Где документы</a>
            </div>
        @endif
    </div>

    @if ($carPhotos->isNotEmpty())
        <section class="mt-5"><x-offer.gallery :photos="$carPhotos" :alt="$t->title()" tiles/></section>
    @endif
    {{-- Документы — ссылкой своей задачи (`/trip/{code}/files/{id}`): входа на странице нет, шторки документов тоже. --}}
    @if ($carPapers->isNotEmpty())
        <section class="mt-5">
            <h2 class="list-head">Документы</h2>
            <div class="flex flex-col">
                @foreach ($carPapers as $m)
                    <a href="{{ $base }}/files/{{ $m->id }}" target="_blank" rel="noopener" class="file-row">
                        <x-ui.file-icon :name="$m->file_name" :mime="$m->mime_type"/>
                        <span class="min-w-0 flex-1 truncate">{{ $m->file_name }}</span>
                        <span class="shrink-0 text-sm text-ink-muted">{{ $m->humanReadableSize }}</span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    <div class="steps mt-6">
        @foreach ($t->steps() as $s)
            @continue(in_array($s['key'], ['logist', 'executor'], true))
            <div class="step step--{{ $s['status'] }}">
                <span class="step-dot">@if ($s['status'] === 'done')<x-ui.icon name="check" class="size-3"/>@endif</span>
                <div class="step-body">
                    <div class="step-head">
                        <span class="step-title flex-1">{{ $s['title'] }}</span>
                        @if ($s['status'] === 'done' && $s['at'])<span class="nums shrink-0 text-sm text-ink-dim">{{ \App\Support\Ago::stamp($s['at'], time: true) }}</span>@endif
                    </div>
                    @if ($s['hint'])<p class="step-hint">{{ $s['hint'] }}</p>@endif
                </div>
            </div>
        @endforeach
    </div>

    @if ($errors->any())<x-ui.flash tone="danger" class="mt-4">{{ $errors->first() }}</x-ui.flash>@endif

    {{-- Забирать с нашей парковки по сделке XCar (18.10.2026): пропуск сделки на этого исполнителя — QR показать на воротах. --}}
    @php
        $parkPass = ! $t->state->picked() && ($v = $t->offer?->parkVehicle()->first()) ? $v->dealPass() : null;
        $parkPass = $parkPass && $t->executor && $parkPass->name === $t->executor->name ? $parkPass : null;
    @endphp
    @if ($parkPass)
        <section class="mt-6">
            <div class="list-cap">Пропуск на парковку</div>
            <div class="pass-card">
                <div class="pass-state pass-state--open"><x-ui.icon name="check-circle" class="size-5"/>Покажите QR на воротах</div>
                <div class="pass-qr mx-auto max-w-64">{!! \App\Park\PassQr::svg($parkPass) !!}</div>
                <div class="nums pb-4 text-center text-sm tracking-[.2em] text-ink-muted">{{ trim(chunk_split($parkPass->code, 5, ' ')) }}</div>
            </div>
            @if ($parkPass->vehicle?->yard)<div class="list mt-3"><x-pickup.yard :yard="$parkPass->vehicle->yard"/></div>@endif
        </section>
    @endif

    @if ($step === 'call')
        <section class="mt-6">
            <h2 class="list-head">Созвонились с владельцем</h2>
            <form method="post" action="{{ $base }}/call" class="flex flex-col gap-4">
                @csrf
                <div class="fields">
                    <x-ui.field name="pickup_at" id="trip-at" label="Когда вывоз" type="datetime-local" default-time="12:00" :value="old('pickup_at', $t->pickup_at?->format('Y-m-d\TH:i'))" :min="now()->format('Y-m-d\TH:i')" required/>
                    <x-ui.field name="pickup_place" id="trip-place" label="Где забрать" type="textarea" :value="old('pickup_place', $from)" required/>
                    <x-ui.field name="call_note" id="trip-note" label="Детали" type="textarea" :value="old('call_note', $t->call_note)"/>
                </div>
                <div class="flex gap-2">
                    <button type="submit" formaction="{{ $base }}/missed" class="btn btn-quiet shrink-0" formnovalidate>Не дозвонился</button>
                    <x-ui.button class="min-w-0 flex-1">Созвонились</x-ui.button>
                </div>
            </form>
        </section>
    @elseif ($step === 'docs')
        <form method="post" action="{{ $base }}/received" class="mt-6" data-turbo-confirm="Документы у вас?">@csrf<x-ui.button block>Получил документы</x-ui.button></form>
    @elseif ($step === 'pickup')
        <section class="mt-6">
            <h2 class="list-head">Забрали ТС</h2>
            <form method="post" action="{{ $base }}/pickup" class="flex flex-col gap-4" data-controller="reveal">
                @csrf
                @if ($t->isPickup())<x-logistics.pickup-fields :t="$t" :url="$base.'/media'"/>@endif
                <x-ui.button block>Забрал</x-ui.button>
            </form>
        </section>
    @elseif ($step === 'deliver')
        <section class="mt-6">
            <x-logistics.photos :t="$t" at="delivery" edit :url="$base.'/media'"/>
            <form method="post" action="{{ $base }}/deliver" class="mt-4" data-turbo-confirm="ТС на месте?">@csrf<x-ui.button block>Привёз</x-ui.button></form>
        </section>
    @elseif ($blocked)
        <p class="mt-6 rounded-(--radius-m) bg-surface-2 px-4 py-3 text-ink-muted">{{ $blocked }}</p>
    @elseif ($t->state === S::Delivered)
        <p class="mt-6 text-accent-text">Готово, спасибо</p>
    @endif
</x-pickup.layout>
