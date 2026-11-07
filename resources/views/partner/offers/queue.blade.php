{{-- «3 из 57 ‹ ›» редактора поставщика: место в очереди и соседи (`OfferController::queue`), без возврата в список. --}}
<div class="partner-queue">
    <span class="nums text-sm text-ink-muted">{{ $queue['at'] }} из {{ $queue['total'] }}</span>
    @if ($queue['prev'])<a href="/offers/{{ $queue['prev'] }}" class="btn btn-s btn-quiet btn-round" aria-label="Предыдущее" data-turbo-action="replace"><x-ui.icon name="chevron-left" class="size-5"/></a>@else<span class="btn btn-s btn-quiet btn-round opacity-40" aria-hidden="true"><x-ui.icon name="chevron-left" class="size-5"/></span>@endif
    @if ($queue['next'])<a href="/offers/{{ $queue['next'] }}" class="btn btn-s btn-quiet btn-round" aria-label="Следующее" data-turbo-action="replace"><x-ui.icon name="chevron-right" class="size-5"/></a>@else<span class="btn btn-s btn-quiet btn-round opacity-40" aria-hidden="true"><x-ui.icon name="chevron-right" class="size-5"/></span>@endif
</div>
