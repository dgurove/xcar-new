{{-- Фотографии письма лентой: прокручивается пальцем, клик открывает просмотрщик во весь экран (тот же photos,
     что у галереи ТС, только смотреть). Миниатюра тянется из ящика по адресу вложения. --}}
@props(['files', 'base' => '/mail'])
<div class="photo-row mt-3" data-controller="photos" data-photos-readonly-value="true">
    <div class="photo-strip" data-photos-target="grid">
        @foreach ($files as $file)
            <div class="photo-cell" title="{{ $file->filename }}">
                <img src="{{ $base }}/attachments/{{ $file->id }}?thumb=1" data-full="{{ $base }}/attachments/{{ $file->id }}" alt="" loading="lazy" data-action="click->photos#open">
            </div>
        @endforeach
    </div>
</div>
