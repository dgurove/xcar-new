{{-- Шторка «Доп. запрос…» менеджера (27.11.2026, Роман Корабельников; владелец выбрал вариант 2б из макетов): что запросить у
     поставщика — строками с галкой справа, ПТС и осмотр первыми, остальное под «Ещё N»; отмечено то, что он просил в прошлый раз
     (`ExtraWant::last`), раскрыто, если среди отмеченного есть из «Ещё». Комментарий — по желанию. Ответ — `POST /deals/{id}/extra-want`
     (`Actions\WantExtra`). Открывается кнопкой с `aria-controls` в том же `data-controller="sheet"`; `?want=1` — сразу (Telegram), только у шторки шага (`auto`).
     В подтверждении (`bid`, вариант П2 там же): своей формы нет — галки `extra[]` уходят с формой подтверждения, «Готово» закрывает,
     отмеченное — строкой под формой (`extra_pick_controller`); прошлое отмечается при первом открытии, а не сразу. --}}
@props(['deal' => null, 'id', 'back' => null, 'auto' => false, 'bid' => false, 'picked' => null])
@php
    use App\Offers\ExtraWant;
    $choices = ExtraWant::choices();
    $first = $choices->filter(fn ($t) => in_array($t->name, ExtraWant::FIRST, true))->values();
    $rest = $choices->reject(fn ($t) => in_array($t->name, ExtraWant::FIRST, true))->values();
    $checked = array_map('intval', $bid ? (array) old('extra', $picked ?? []) : (array) old('picks', ExtraWant::last(auth()->user())));
    $openRest = $rest->contains(fn ($t) => in_array($t->id, $checked, true));
    $name = $bid ? 'extra[]' : 'picks[]';
    $row = fn ($t) => '<label class="row row-check"><span class="min-w-0 flex-1">'.e(ExtraWant::label($t)).'</span><span class="check"><input type="checkbox" name="'.$name.'" value="'.$t->id.'"'
        .($bid ? ' data-extra-pick-target="box" data-word="'.e(ExtraWant::label($t)).'" data-action="change->extra-pick#sync"' : '')
        .(in_array($t->id, $checked, true) ? ' checked' : '').'></span></label>';
@endphp
<x-ui.sheet :id="$id" title="Что запросить у поставщика" :open="($auto && request()->boolean('want')) || (! $bid && $errors->has('picks') && old('_sheet') === $id)">
    @unless ($bid)
    <form method="post" action="/deals/{{ $deal->id }}/extra-want" class="flex flex-col gap-4">
        @csrf
        <input type="hidden" name="_sheet" value="{{ $id }}">
        @if ($back)<input type="hidden" name="back" value="{{ $back }}">@endif
    @else
    <div class="flex flex-col gap-4">
    @endunless
        <div class="list">
            @foreach ($first as $t){!! $row($t) !!}@endforeach
            @if ($rest->isNotEmpty())
                <details class="group" @if ($openRest) open @endif>
                    <summary class="row cursor-pointer list-none text-accent-text [&::-webkit-details-marker]:hidden group-open:hidden"><span class="min-w-0 flex-1">Ещё {{ $rest->count() }}</span><x-ui.icon name="chevron-right" class="size-4 text-ink-dim"/></summary>
                    <div class="list !rounded-none !bg-transparent">@foreach ($rest as $t){!! $row($t) !!}@endforeach</div>
                </details>
            @endif
        </div>
        @if ($bid)
            <div class="sheet-foot"><button type="button" class="btn btn-accent btn-block" data-action="sheet#close">Готово</button></div>
        </div>
        @else
            @error('picks')<p class="field-error -mt-2 px-1">{{ $message }}</p>@enderror
            <div class="fields"><x-ui.field name="comment" :id="$id.'-comment'" label="Комментарий" placeholder="Осмотр в субботу" :value="old('comment')"/></div>
            <x-ui.sheet-foot>Попросить XCAR</x-ui.sheet-foot>
        </form>
        @endif
</x-ui.sheet>
