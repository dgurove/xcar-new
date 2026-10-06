{{-- «Показ» — под описанием «Транспортного средства», только админу: до когда принимаем подтверждения, кому показывать
     (волны, шторкой), «Рекомендуем», чат с покупателями, запрет шеринга, «Можно в гараж» (менеджер выбирает «В гараж» при подтверждении). Одно на редактор и карточка строки; $grid — сетка
     колонок, $summary — кто кому из покупателей уже открыл (только редактор). --}}
@if (auth()->user()->canManageCrm())
<section class="mt-6">
    <h3 class="form-subtitle">Показ</h3>
    {{-- Блок пришёл в форме: его галки и «кому» без отметки считаются снятыми (`OfferRequest::payload`). --}}
    <input type="hidden" name="_show" value="1">
    <div class="{{ $grid }}">
        @php($close = old('bids_close_at', $offer->bids_close_at?->format('Y-m-d\TH:i')))
        <div class="field col-span-2 @4xl:col-span-1 {{ $errors->has('bids_close_at') ? 'field-invalid' : '' }}" data-controller="evening">
            <label for="f-bids_close_day" class="field-label">Приём подтверждений до</label>
            <div class="grid grid-cols-[1fr_auto] gap-2">
                <input id="f-bids_close_day" type="date" class="field-input" value="{{ $close ? substr($close, 0, 10) : '' }}" data-evening-target="day" data-action="change->evening#day">
                <input type="time" class="field-input" value="{{ $close ? substr($close, 11, 5) : '' }}" aria-label="Время" data-evening-target="time" data-action="change->evening#sync">
            </div>
            <input type="hidden" name="bids_close_at" value="{{ $close }}" data-evening-target="out">
            @error('bids_close_at')<p class="field-error">{{ $message }}</p>@enderror
        </div>
        <div class="field col-span-2">
            <span class="field-label">Кому показывать</span>
            @include('admin.offers.fields.audience')
        </div>
        @if (($summary ?? collect())->isNotEmpty())
            <div class="col-span-full flex flex-col gap-1.5 text-sm">
                @foreach ($summary as $row)
                    <div class="flex items-center gap-2"><x-ui.person :user="$row['manager']"/><span class="text-ink-muted">открыл {{ $row['buyers'] }} {{ \App\Support\Plural::of($row['buyers'], ['покупателю', 'покупателям', 'покупателям']) }}</span></div>
                @endforeach
            </div>
        @endif
        <div class="flags col-span-full">
            <x-ui.check name="recommended" :checked="$offer->recommended">Рекомендуем</x-ui.check>
            <x-ui.check name="chat_enabled" :checked="$offer->chat_enabled">Чат с покупателями</x-ui.check>
            <x-ui.check name="share_locked" :checked="$offer->share_locked">Запретить шеринг</x-ui.check>
            <x-ui.check name="garage_allowed" :checked="$offer->garage_allowed">Можно в гараж</x-ui.check>
        </div>
    </div>
</section>
@endif
