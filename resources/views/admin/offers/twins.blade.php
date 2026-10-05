{{-- Другие предложения с этим VIN или номером убытка (OfferController::twins, twins_controller): строкой — название, номер,
     состояние. Чужое модератору (опубликованное, из закупки) — без ссылки: внутрь ему нельзя, но знать надо. Второго
     предложения на ту же машину не бывает (`Cars\Identity`): у черновика — «Это она», всё его уходит к найденному. --}}
@foreach ($offers as $o)
    @php
        $line = 'box-nested box-urgent flex items-center gap-2 !px-3 !py-2';
        $inner = '<span class="min-w-0 flex-1 truncate">'.e($o->titleWithYear()).'</span>'
            .($o->state === \App\Offers\OfferState::Draft ? '' : '<span class="tag nums shrink-0">№ '.$o->number.'</span>')
            .'<span class="tag shrink-0">'.e($o->state->label()).'</span>';
        $editable = $o->isEditableBy($user);
    @endphp
    <div class="{{ $line }}">
        @if ($editable)
            <a href="/offers/{{ $o->number }}" class="flex min-w-0 flex-1 items-center gap-2">{!! $inner !!}</a>
        @else
            <div class="flex min-w-0 flex-1 items-center gap-2">{!! $inner !!}</div>
        @endif
        @if ($self && $editable)
            <button type="button" data-action="twins#into" data-url="/offers/{{ $self->number }}/into/{{ $o->number }}" data-confirm="Перенести фото, документы и письма в это предложение? Черновика не останется" class="btn btn-s btn-secondary shrink-0">Это она</button>
        @endif
    </div>
@endforeach
