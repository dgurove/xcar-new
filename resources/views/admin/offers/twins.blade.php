{{-- Другие предложения с этим VIN (OfferController::twins, twins_controller): строкой — название, номер, состояние. --}}
@foreach ($offers as $o)
    <a href="/offers/{{ $o->number }}" class="box-nested box-urgent flex items-center gap-2 !px-3 !py-2">
        <span class="min-w-0 flex-1 truncate">{{ $o->titleWithYear() }}</span>
        <span class="tag nums shrink-0">№ {{ $o->number }}</span>
        <span class="tag shrink-0">{{ $o->state->label() }}</span>
    </a>
@endforeach
