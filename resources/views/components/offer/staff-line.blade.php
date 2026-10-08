{{-- Админу на сайте (08.10.2026): у предложения своих действий нет — подтверждает менеджер, интерес оставляют менеджеры
     и покупатели. Одна строка, где его ведут, и переход в CRM: отклики галереи, сделка с менеджером, гараж, подтверждения. --}}
@props(['offer'])
@php
    use App\Support\Surface;
    $car = $offer->garageCar()->with('manager')->first();
    $deal = $car ? null : $offer->deal()->with('buyer')->first();
    [$title, $sub, $count, $href] = match (true) {
        $offer->isGallery() => ['Отклики', null, $offer->interests()->count(), Surface::Crm->url('/gallery?preset=interest&peek='.$offer->number)],
        (bool) $car => ['В гараже', $car->manager?->shortName() ?? 'взяли под себя', null, $car->crmUrl()],
        (bool) $deal => ['Сделка', $deal->buyer?->shortName(), null, $deal->crmUrl()],
        default => ['Подтверждения', null, $offer->bids()->where('state', \App\Offers\BidState::Active)->count(), Surface::Crm->url('/offers/'.$offer->number)],
    };
@endphp
<div {{ $attributes->merge(['class' => 'list']) }}>
    <a href="{{ $href }}" class="row" data-turbo="false">
        <span class="min-w-0 flex-1"><span class="block">{{ $title }}</span>@if ($sub)<span class="row-sub">{{ $sub }}</span>@endif</span>
        @if ($count !== null)<span class="nums text-ink-muted">{{ $count }}</span>@endif
        <x-ui.chevron/>
    </a>
</div>
