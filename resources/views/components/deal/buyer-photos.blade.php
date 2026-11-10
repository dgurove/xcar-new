{{-- Реквизиты покупателя фотографией (10.11.2026, владелец: «не прикрепляют реквизиты»): две плитки — паспорт (физлицо)
     и карточка фирмы (ИП, ООО); нажатие — камера или файл, отправляется само (`Cabinet\DealController::buyerPhotos`):
     покупатель заводится, реквизиты вносим мы. Ниже — прежнее: свои покупатели и данные вручную (`cabinet.deals.contract`). --}}
@props(['deal'])
<div class="buyer-photos">
    @foreach (['passport' => ['Паспорт', 'физлицо', 'user'], 'company' => ['Карточка фирмы', 'ИП или ООО', 'file']] as $kind => [$title, $sub, $icon])
        <form method="post" action="/deals/{{ $deal->id }}/buyer-photos" enctype="multipart/form-data" data-controller="autosubmit" class="contents">
            @csrf
            <input type="hidden" name="kind" value="{{ $kind }}">
            <label class="buyer-photo">
                <input type="file" name="files[]" accept="image/*,application/pdf" multiple class="sr-only" data-action="change->autosubmit#submit">
                <x-ui.row-icon name="{{ $icon }}" tone="accent"/>
                <span class="font-medium">{{ $title }}</span>
                <span class="text-sm text-ink-muted">{{ $sub }}</span>
            </label>
        </form>
    @endforeach
</div>
@error('files')<p class="field-error mt-2">{{ $message }}</p>@enderror
@error('files.*')<p class="field-error mt-2">{{ $message }}</p>@enderror
