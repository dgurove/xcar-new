{{-- Кружок отправителя письма — один на строку почты, карточку «Письма» и ленту: наше — сотрудник (или знак XCAR, и письмо
     с нашего же ящика), письмо вендора с логотипом — логотип вендора отправителя (`SenderVendor`, 17.11.2026, владелец: «вместо
     заглушки с инициалами — логотипы вендоров»), иначе инициалы: у пересылки сотрудника — настоящего отправителя из цитаты,
     у остальных — из «От». --}}
@props(['message', 'size' => 32])
@php
    $m = $message;
    $forwarded = $m->isForwardedByStaff();
    $vendor = \App\Mail\SenderVendor::of($m);
    $logo = $vendor?->logoUrl();
    $ourBox = ! $forwarded && in_array(mb_strtolower((string) $m->from_email), \App\Mail\Message::ownMailboxes(), true);
@endphp
@if ($logo)
    <span class="avatar avatar-vendor" style="width: {{ $size }}px; height: {{ $size }}px" title="{{ $vendor->name }}"><img src="{{ $logo }}" alt="" width="{{ $size }}" height="{{ $size }}" decoding="async"></span>
@elseif ($ourBox)
    <x-chat.avatar :user="null" :size="$size"/>
@elseif ($forwarded)
    <x-ui.avatar :name="\App\Mail\Chains\NodeTitle::who($m)" :email="$m->field('sender')" :size="$size"/>
@elseif ($m->isOurs() && $m->author)
    <x-ui.avatar :user="$m->author" :size="$size"/>
@elseif ($m->isOurs())
    <x-chat.avatar :user="null" :size="$size"/>
@else
    <x-ui.avatar :name="$m->from_name" :email="$m->from_email" :size="$size"/>
@endif
