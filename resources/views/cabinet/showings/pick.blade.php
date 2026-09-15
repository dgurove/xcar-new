{{-- Выбор предложений для покупателя или группы: мини-карточки с галкой, уже открытые — отмечены и не трогаются. --}}
<turbo-frame id="pick-frame">
    <form method="get" action="/lk/pokazy/vybor" class="mb-4" data-turbo-frame="pick-frame" data-turbo-action="replace" data-controller="autosubmit">
        @if ($buyer)<input type="hidden" name="user" value="{{ $buyer->id }}">@else<input type="hidden" name="group" value="{{ $group->id }}">@endif
        <input type="search" name="q" value="{{ $q }}" placeholder="Марка, модель, номер" class="field-input field-s" autocomplete="off" data-action="input->autosubmit#debounced">
    </form>
    @if ($offers->isEmpty())
        <x-ui.empty>{{ $q ? 'Ничего не нашлось.' : 'Открытых предложений сейчас нет.' }}</x-ui.empty>
    @else
        <form method="post" action="/lk/pokazy" data-controller="select" data-turbo-frame="_top">
            @csrf
            @if ($buyer)<input type="hidden" name="users[]" value="{{ $buyer->id }}">@else<input type="hidden" name="groups[]" value="{{ $group->id }}">@endif
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                @foreach ($offers as $offer)
                    @php $done = in_array($offer->id, $already, true); @endphp
                    <label class="pick-card {{ $done ? 'pick-card--done' : '' }}">
                        <input type="checkbox" name="offers[]" value="{{ $offer->id }}" @disabled($done) @checked($done) data-select-target="box" data-action="select#count">
                        <span class="pick-card-media"><x-offer.photo :media="$offer->mainPhoto()" sizes="200px"/></span>
                        <span class="pick-card-body">
                            <span class="line-clamp-2 text-sm leading-snug">{{ $offer->titleWithYear() }}</span>
                            @if ($offer->asking_price)<span class="nums text-sm text-ink-muted">{{ number_format($offer->asking_price, 0, '', ' ') }} ₽</span>@endif
                        </span>
                        <span class="pick-card-check"><x-ui.icon name="check" class="size-4"/></span>
                    </label>
                @endforeach
            </div>
            <div class="sticky bottom-0 -mx-1 mt-4 bg-surface px-1 pb-1 pt-3">
                <button type="submit" class="btn btn-accent w-full" data-select-target="submit" disabled>Открыть <span class="nums" data-select-target="count"></span></button>
            </div>
        </form>
    @endif
</turbo-frame>
