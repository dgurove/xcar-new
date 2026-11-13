{{-- Реквизиты покупателя фотографией (10.11.2026, владелец: «не прикрепляют реквизиты»): плитки по виду покупателя, а не по
     документу (13.11.2026, Тужиков: ИП прислал «Карточкой фирмы», а для ИП нужен и паспорт — «Карточка фирмы — ИП или ООО»
     сбивала): «Физлицо» — паспорт, «ООО» — карточка фирмы, «ИП» — две кнопки, «Паспорт» и «Реквизиты», каждая отправляется сама,
     вторую можно позже. Нажатие — камера или файл (`Cabinet\DealController::buyerPhotos`): покупатель заводится, реквизиты вносим
     мы. `only` — одна часть ИП, которой ещё нет («Ещё нужен паспорт ИП» в `cabinet.deals.buyer-block`). --}}
@props(['deal', 'only' => null])
@php
    $action = '/deals/'.$deal->id.'/buyer-photos';
    $pick = 'change->autosubmit#submit';
@endphp
@if ($only)
    <form method="post" action="{{ $action }}" enctype="multipart/form-data" data-controller="autosubmit">
        @csrf
        <input type="hidden" name="kind" value="ip"><input type="hidden" name="part" value="{{ $only }}">
        <label class="buyer-photo">
            <input type="file" name="files[]" accept="image/*,application/pdf" multiple class="sr-only" data-action="{{ $pick }}">
            <x-ui.row-icon :name="$only === 'passport' ? 'camera' : 'file'" size="s"/>
            <span class="min-w-0"><span class="block font-medium">{{ $only === 'passport' ? 'Ещё нужен паспорт ИП' : 'Ещё нужны реквизиты ИП' }}</span><span class="block text-sm text-ink-muted">{{ $only === 'passport' ? 'разворот и прописка' : 'карточка или скрин из банка' }}</span></span>
        </label>
    </form>
@else
<div class="buyer-photos">
    @foreach (['passport' => ['Физлицо', 'паспорт', 'camera'], 'company' => ['ООО', 'карточка фирмы', 'file']] as $kind => [$title, $sub, $icon])
        <form method="post" action="{{ $action }}" enctype="multipart/form-data" data-controller="autosubmit" class="contents">
            @csrf
            <input type="hidden" name="kind" value="{{ $kind }}">
            <label class="buyer-photo">
                <input type="file" name="files[]" accept="image/*,application/pdf" multiple class="sr-only" data-action="{{ $pick }}">
                <x-ui.row-icon :name="$icon" size="s"/>
                <span class="min-w-0"><span class="block font-medium">{{ $title }}</span><span class="block text-sm text-ink-muted">{{ $sub }}</span></span>
            </label>
        </form>
    @endforeach
    <div class="buyer-photo buyer-photo-ip">
        <div class="flex items-center gap-3">
            <x-ui.row-icon name="user" size="s"/>
            <span class="min-w-0"><span class="block font-medium">ИП</span><span class="block text-sm text-ink-muted">паспорт и реквизиты</span></span>
        </div>
        <div class="buyer-photo-parts">
            @foreach (['passport' => ['Паспорт', 'camera'], 'card' => ['Реквизиты', 'file']] as $part => [$label, $icon])
                <form method="post" action="{{ $action }}" enctype="multipart/form-data" data-controller="autosubmit" class="contents">
                    @csrf
                    <input type="hidden" name="kind" value="ip"><input type="hidden" name="part" value="{{ $part }}">
                    <label class="btn btn-quiet btn-s">
                        <input type="file" name="files[]" accept="image/*,application/pdf" multiple class="sr-only" data-action="{{ $pick }}">
                        <x-ui.icon :name="$icon" class="size-4"/>{{ $label }}
                    </label>
                </form>
            @endforeach
        </div>
    </div>
</div>
@endif
@error('files')<p class="field-error mt-2">{{ $message }}</p>@enderror
@error('files.*')<p class="field-error mt-2">{{ $message }}</p>@enderror
