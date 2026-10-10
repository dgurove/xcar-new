{{-- QR ссылки на оплату картинкой PNG, а не SVG: долгое нажатие на телефоне само даёт «Сохранить / Поделиться», и тот же
     файл уходит кнопками «Скачать QR» и «Отправить QR» без запроса на сервер (10.10.2026, владелец: QR не скачать и не переслать).
     Адрес — под кодом, нажатие копирует полный. Кнопки называют предмет: рядом бывает «Отправить» текста сообщения.
     main=false — главное действие шторки другое (текст для отправки), и без листа «Скачать QR» лаймовой не становится. --}}
@props(['link', 'size' => 'w-52', 'main' => true])
@php $name = 'QR оплаты '.\App\Support\Money::rub($link->amount).'.png'; @endphp
<div {{ $attributes->class('flex flex-col items-center gap-3') }} data-controller="qr-file" data-qr-file-name-value="{{ $name }}" @if ($main) data-qr-file-main-value="true" @endif>
    {{-- Поле тишины 4 модуля внутри картинки: пересланный файл сканируется и без белой рамки страницы. --}}
    <img src="data:image/png;base64,{{ base64_encode(\App\Support\Qr::png($link->url(), 16, 4)) }}" alt="QR-код ссылки на оплату" class="{{ $size }} rounded-(--radius-l) bg-white" data-qr-file-target="image">
    <x-ui.copy-code :value="$link->url()" :text="$link->shortUrl()" done="Ссылка в буфере" class="break-all text-center text-ink-muted"/>
    <div class="flex w-full gap-2">
        <button type="button" class="btn btn-s btn-quiet flex-1" data-action="qr-file#download" data-qr-file-target="download"><x-ui.icon name="download" class="size-5"/> Скачать QR</button>
        <button type="button" class="btn btn-s btn-quiet flex-1" data-action="qr-file#share" data-qr-file-target="share"><x-ui.icon name="share" class="size-5"/> Отправить QR</button>
    </div>
</div>
