{{-- Письмо строкой во «Входящих» и «Отправленных» (17.11.2026, макет выбран владельцем): кто (в «Отправленных» — «Кому:»),
     скрепка и дата; машина (значок типа ТС и «марка модель, год») или тема; первые слова. Без номера убытка и этапа (владелец:
     «этап писать не нужно», «номер убытка не писать»). У цепочки «Из писем» — её название, у письма без машины — первые
     слова. Непрочитанное — жирным. Строка — ссылка на письмо справа (`Detail`, ключ — id письма); наше письмо, что не ушло
     или упало, — словом у даты. Метки ящика у строки нет: ящик выбирают чипом над списком. Дата — со временем; справа внизу
     кружок — сколько писем во всей переписке по машине (size). Слева столбцом одной ширины — кружок отправителя (логотип
     страховой, `x-mail.sender-avatar`) у имени и значок типа ТС у машины: имя, тема и машина начинаются с одной линии
     (17.11.2026, владелец). На телефоне строка открывает окно писем дела, как из сделки
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
        (bool) $offer => [$offer->category(), $offer->titleWithYear()],
        (bool) $vehicle => [$vehicle->category ?? \App\Cars\Category::guess($vehicle->titleWithYear()), $vehicle->titleWithYear()],
        (bool) $chain => [\App\Cars\Category::guess($chain->title()), $chain->title()],
        default => null,
    };
    $to = $sent ? $m->addresses->where('kind', 'to')->sortBy('position')->first() : null;
    // Кружок — кто писал; в «Отправленных» — кому (страховая — её логотипом, как у отправителя).
    $toVendor = $to ? \App\Vendors\Vendor::forSender($to->email, $park ? \App\Mail\Scope::Park : \App\Mail\Scope::Offers) : null;
    // «Кому:» — адресом, не именем (17.11.2026, владелец: «почты, а не погоняла»).
    $who = $sent ? ($to ? mb_strtolower((string) ($to->email ?: $to->name)) : trim((string) $m->to_preview)) : NodeTitle::who($m);
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
    <span class="inbox-lead">
        @if (! $sent)<x-mail.sender-avatar :message="$m" :size="20"/>
        @elseif ($toVendor?->logoUrl())<span class="avatar avatar-vendor" style="width: 20px; height: 20px" title="{{ $toVendor->name }}"><img src="{{ $toVendor->logoUrl() }}" alt="" width="20" height="20" decoding="async"></span>
        @else<x-ui.avatar :name="$to?->name" :email="$to?->email ?? $who" :size="20"/>@endif
    </span>
    <span class="inbox-l1">
        <span class="inbox-who">@if ($sent)<span class="inbox-to">Кому:</span> @endif{{ $who ?: '—' }}</span>
        @if ($files)<x-ui.icon name="clip" class="inbox-clip size-4"/>@endif
        @if ($unsent)<span class="inbox-unsent {{ $unsent === \App\Mail\SendState::Failed ? 'text-danger' : 'text-urgent' }}">{{ $unsent->label() }}</span>@endif
        <span class="inbox-when nums">{{ $when }}</span>
    </span>
    {{-- Вторая строка — машина, если узнана (значок типа ТС и «марка модель, год»), иначе тема без «RE: / Fwd:» и «ч.2»; третья —
         всегда первые слова письма (17.11.2026, владелец: «не видно текста у писем, где распозналась машина — пусть машина
         вместо темы, а не вместо текста»). --}}
    @if ($car)
        <span class="inbox-lead inbox-lead--car"><x-ui.cat-icon :category="$car[0] ?? \App\Cars\Category::Passenger"/></span>
        <span class="inbox-subj inbox-car"><span class="truncate">{{ $car[1] }}</span></span>
    @else
        <span class="inbox-subj">{{ \App\Mail\Topic::clean($m->subject) ?: '(без темы)' }}</span>
    @endif
    <span class="inbox-l3">
        <span class="inbox-snip">{{ NodeTitle::words($m, 160) ?: ($files ? 'Только вложения' : 'Без своих слов') }}</span>
        @if ($size > 1)<span class="inbox-count nums" title="Писем по машине">{{ $size }}</span>@endif
    </span>
</a>
