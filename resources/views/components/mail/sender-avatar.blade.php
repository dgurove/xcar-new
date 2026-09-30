{{-- Кружок отправителя письма — один на строку почты, карточку «Письма» и ленту: наше — сотрудник (или знак XCar),
     пересланное сотрудником письмо вендора — настоящий отправитель из цитаты, иначе имя и адрес из «От». --}}
@props(['message', 'size' => 32])
@php
    $m = $message;
    $forwarded = $m->isForwardedByStaff();
@endphp
@if ($forwarded)
    <x-ui.avatar :name="\App\Mail\Chains\NodeTitle::who($m)" :email="$m->field('sender')" :size="$size"/>
@elseif ($m->isOurs() && $m->author)
    <x-ui.avatar :user="$m->author" :size="$size"/>
@elseif ($m->isOurs())
    <x-chat.avatar :user="null" :size="$size"/>
@else
    <x-ui.avatar :name="$m->from_name" :email="$m->from_email" :size="$size"/>
@endif
