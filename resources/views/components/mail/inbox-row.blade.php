{{-- Письмо строкой во «Входящих» и «Отправленных» (17.11.2026, макет выбран владельцем): кто (в «Отправленных» — «Кому:»),
     скрепка и дата; тема как есть; машина — логотип вендора и «марка модель, год», без номера убытка и этапа (владелец:
     «этап писать не нужно», «номер убытка не писать»). У цепочки «Из писем» — её название, у письма без машины — первые
     слова. Непрочитанное — жирным. Строка — ссылка на письмо справа (`Detail`, ключ — id письма); наше письмо, что не ушло
     или упало, — словом у даты. Метки ящика у строки нет: ящик выбирают чипом над списком. Дата — со временем; справа внизу
     кружок — сколько писем во всей переписке по машине (size). На телефоне строка открывает окно писем дела, как из сделки
     (letters — его адрес, `mail_row_controller`), а не лист справа. --}}
@props(['message', 'files' => 0, 'sent' => false, 'park' => false, 'size' => 1, 'base' => '/work/mail', 'queue' => '/offers/from-mail'])
@php
    use App\Mail\Chains\NodeTitle;
    $m = $message;
    $t = $m->thread;
    $offer = $park ? null : $t?->offer;
    $vehicle = $park ? $t?->vehicle : null;
    $chain = ! $offer && ! $vehicle ? $t?->candidate : null;
    $car = match (true) {
        (bool) $offer => [$offer->vendor, $offer->titleWithYear()],
        (bool) $vehicle => [$vehicle->vendor, $vehicle->titleWithYear()],
        (bool) $chain => [$chain->vendor, $chain->title()],
        default => null,
    };
    $to = $sent ? $m->addresses->where('kind', 'to')->sortBy('position')->first() : null;
    $who = $sent ? ($to ? ($to->name ?: $to->email) : trim((string) $m->to_preview)) : NodeTitle::who($m);
    $unread = ! $sent && ! $m->is_seen;
    $when = $m->date_at?->isToday() ? $m->date_at->format('H:i') : \App\Support\Ago::at($m->date_at, true);
    // Окно писем дела на этом письме — тот же адрес, что у карточек «Все делами».
    $letters = match (true) {
        (bool) $vehicle => '/cars/'.$vehicle->id.'/letters?at='.$m->id,
        (bool) $offer => '/offers/'.$offer->number.'/letters?at='.$m->id,
        (bool) ($t?->candidate_id) => $queue.'/'.$t->candidate_id.'/letters?at='.$m->id,
        default => $base.'/'.$m->thread_id.'/window?at='.$m->id,
    };
    $unsent = $m->isOurs() && $m->send_state && $m->send_state !== \App\Mail\SendState::Sent ? $m->send_state : null;
@endphp
<a href="{{ \App\Support\Detail::url($m->id) }}" @class(['inbox-row', 'is-unread' => $unread]) id="inbox-{{ $m->id }}" data-detail-key="{{ $m->id }}" data-detail-link data-turbo-frame="detail" data-turbo-prefetch="false" data-letters="{{ $letters }}" data-search-row>
    <span class="inbox-l1">
        <span class="inbox-who">@if ($sent)<span class="inbox-to">Кому:</span> @endif{{ $who ?: '—' }}</span>
        @if ($files)<x-ui.icon name="clip" class="inbox-clip size-4"/>@endif
        @if ($unsent)<span class="inbox-unsent {{ $unsent === \App\Mail\SendState::Failed ? 'text-danger' : 'text-urgent' }}">{{ $unsent->label() }}</span>@endif
        <span class="inbox-when nums">{{ $when }}</span>
    </span>
    {{-- Тема без «RE: / Fwd:» и «ч.2» — в узкой колонке приставки съедали место. --}}
    <span class="inbox-subj">{{ \App\Mail\Topic::clean($m->subject) ?: '(без темы)' }}</span>
    <span class="inbox-l3">
        @if ($car)
            <span class="inbox-car">@if ($car[0])<x-vendor.logo :vendor="$car[0]"/>@endif<span class="truncate">{{ $car[1] }}</span></span>
        @else
            <span class="inbox-snip">{{ NodeTitle::words($m, 160) ?: 'Без своих слов' }}</span>
        @endif
        @if ($size > 1)<span class="inbox-count nums" title="Писем по машине">{{ $size }}</span>@endif
    </span>
</a>
