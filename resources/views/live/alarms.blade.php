{{-- Шторка тревоги «нет покупателя» (`FragmentController::alarms`): горит — сверху, вышел недавно — ниже; строка — машина,
     срок и сколько осталось (номер убытка — только сотрудникам XCar), нажатие — задача вывоза или предложение. Пусто — тревога погасла. --}}
<turbo-frame id="{{ $frame }}">
    @if ($burning->isEmpty() && $over->isEmpty())
        <p class="py-6 text-center text-ink-muted">Все машины с покупателем</p>
    @endif
    @foreach (['Горит' => $burning, 'Срок вышел' => $over] as $head => $list)
        @continue($list->isEmpty())
        <div class="list-head mt-2">{{ $head }}</div>
        <div class="list">
            @foreach ($list as $offer)
                @php $href = $href($offer); @endphp
                <a @if ($href) href="{{ $href }}" @endif class="row alarm-row" data-turbo-frame="_top" @if ($href && str_starts_with($href, 'http')) data-turbo="false" @endif>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate">{{ $offer->titleWithYear() }}</span>
                        <span class="row-sub nums text-danger">{{ $offer->binding_until->isPast() ? 'вышел '.\App\Offers\Binding::date($offer->binding_until) : 'до '.\App\Offers\Binding::date($offer->binding_until).', '.\App\Offers\Binding::word($offer) }}</span>
                    </span>
                    @if ($offer->claim_ref && auth()->user()->isStaff())<span class="shrink-0 text-sm text-ink-muted nums">{{ $offer->claim_ref }}</span>@endif
                    @if ($href)<x-ui.chevron/>@endif
                </a>
            @endforeach
        </div>
    @endforeach
</turbo-frame>
