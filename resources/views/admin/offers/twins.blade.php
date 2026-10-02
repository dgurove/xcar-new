{{-- Другие предложения с этим VIN или номером убытка (OfferController::twins, twins_controller): строкой — название, номер,
     состояние. Чужое модератору (опубликованное, из закупки) — без ссылки: внутрь ему нельзя, но знать надо. --}}
@foreach ($offers as $o)
    @php
        $line = 'box-nested box-urgent flex items-center gap-2 !px-3 !py-2';
        $inner = '<span class="min-w-0 flex-1 truncate">'.e($o->titleWithYear()).'</span>'
            .($o->state === \App\Offers\OfferState::Draft ? '' : '<span class="tag nums shrink-0">№ '.$o->number.'</span>')
            .'<span class="tag shrink-0">'.e($o->state->label()).'</span>';
    @endphp
    @if ($o->isEditableBy($user))
        <a href="/offers/{{ $o->number }}" class="{{ $line }}">{!! $inner !!}</a>
    @else
        <div class="{{ $line }}">{!! $inner !!}</div>
    @endif
@endforeach
