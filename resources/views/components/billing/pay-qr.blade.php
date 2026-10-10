{{-- QR ссылки на оплату картинкой PNG, а не SVG: долгое нажатие на телефоне само даёт «Сохранить / Поделиться», и тот же
     файл уходит кнопками «Скачать» и «Отправить» без запроса на сервер (10.10.2026, владелец: QR не скачать и не переслать). --}}
@props(['link', 'size' => 'w-52'])
@php $name = 'QR оплаты '.\App\Support\Money::rub($link->amount).'.png'; @endphp
<div {{ $attributes->class('flex flex-col items-center gap-3') }} data-controller="qr-file" data-qr-file-name-value="{{ $name }}">
    {{-- Поле тишины 4 модуля внутри картинки: пересланный файл сканируется и без белой рамки страницы. --}}
    <img src="data:image/png;base64,{{ base64_encode(\App\Support\Qr::png($link->url(), 16, 4)) }}" alt="QR-код ссылки на оплату" class="{{ $size }} rounded-(--radius-l) bg-white" data-qr-file-target="image">
    <div class="flex w-full gap-2">
        <button type="button" class="btn btn-s btn-quiet flex-1" data-action="qr-file#download" data-qr-file-target="download"><x-ui.icon name="download" class="size-5"/> Скачать</button>
        <button type="button" class="btn btn-s btn-quiet flex-1" data-action="qr-file#share" data-qr-file-target="share"><x-ui.icon name="share" class="size-5"/> Отправить</button>
    </div>
</div>
