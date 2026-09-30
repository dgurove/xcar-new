{{-- Закупка строкой в группе: название во всю ширину, ниже срок словом и сколько цен названо. --}}
@props(['card'])
@php $purchase = $card->purchase; $cars = $card->cars; $rated = $card->rated; @endphp
<a href="{{ $card->url() }}" class="row">
    <span class="min-w-0 flex-1">
        <span class="block truncate">{{ $card->title() }}</span>
        <span class="row-sub"><x-purchase.deadline :purchase="$purchase" class="!text-sm"/>@if ($cars > 0)<span class="nums">{{ $rated >= $cars ? 'названы все цены' : 'названо '.$rated.' из '.$cars }}</span>@endif</span>
    </span>
    <x-ui.chevron/>
</a>
