{{-- Карточка встречи — подписание у поставщика (`Offers\Signing`, 11.11.2026, владелец: «запись на встречу должна быть красиво
     оформлена, а не тупо текстом»): листок календаря, день недели и время, адрес, «В календарь» (.ics) и «Маршрут».
     `confirmed` — запись подтверждена поставщиком, иначе «ждём подтверждения». `ics` — адрес файла календаря. --}}
@props(['deal', 'confirmed' => false, 'ics'])
@php $at = $deal->signing_at->copy()->timezone('Europe/Moscow'); @endphp
<div {{ $attributes->class('appt') }}>
    <div class="appt-head">
        <div class="appt-cal" aria-hidden="true">
            <span class="appt-month">{{ mb_strtoupper(mb_substr($at->translatedFormat('M'), 0, 3)) }}</span>
            <span class="appt-day nums">{{ $at->day }}</span>
        </div>
        <div class="min-w-0 flex-1">
            {{-- Что за встреча — заголовок шага над карточкой; тут — когда. --}}
            <p class="nums text-lg font-medium">{{ \Illuminate\Support\Str::ucfirst($at->translatedFormat('l')) }}, {{ $at->format('H:i') }}</p>
            <p @class(['mt-0.5 text-sm', 'text-open' => $confirmed, 'text-ink-muted' => ! $confirmed])>{{ $confirmed ? 'Запись подтверждена' : 'Ждём подтверждения записи' }}</p>
        </div>
    </div>
    @if ($deal->signing_address)
        <a href="https://yandex.ru/maps/?text={{ rawurlencode($deal->signing_address) }}" target="_blank" rel="noopener" class="appt-place"><x-ui.place>{{ $deal->signing_address }}</x-ui.place></a>
    @endif
    <div class="appt-acts">
        <a href="{{ $ics }}" class="btn btn-quiet btn-s" data-turbo="false"><x-ui.icon name="calendar" class="size-4"/>В календарь</a>
        @if ($deal->signing_address)
            <a href="https://yandex.ru/maps/?rtext=~{{ rawurlencode($deal->signing_address) }}" target="_blank" rel="noopener" class="btn btn-quiet btn-s"><x-ui.icon name="map-pin" class="size-4"/>Маршрут</a>
        @endif
    </div>
</div>
