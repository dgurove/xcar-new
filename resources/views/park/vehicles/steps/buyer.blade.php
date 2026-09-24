{{-- Шаг «Покупатель» (выдача по QR). Анкеты нет — плашка: ссылка для покупателя (нажатие копирует) и «Отправить
     страховой ещё раз». Анкета есть — плашка-контакт: кто и когда заберёт с кнопкой звонка, почта, запрос страховой
     (открывает письма); под ней «Страховая подтвердила» главной во всю ширину и «Не покупатель» красным текстом. Формы — вне act-form
     (show.blade.php), кнопки ссылаются на них атрибутом form. --}}
@if (! $pass)
    <div class="list mt-2">
        <button type="button" class="row justify-between w-full text-left" data-controller="copy" data-copy-text-value="{{ $vehicle->pickupUrl() }}" data-copy-done-value="Ссылка в буфере" data-action="copy#copy:prevent">
            <span class="min-w-0"><span class="block">Ссылка для покупателя</span><span class="block truncate text-sm text-ink-muted">{{ preg_replace('~^https?://~', '', $vehicle->pickupUrl()) }}</span></span>
            <x-ui.icon name="copy" class="size-5 shrink-0 text-ink-dim"/>
        </button>
        @if ($canManage)
            <button type="submit" form="pickup-link-form" class="row justify-between w-full text-left" data-turbo-confirm="Отправить страховой ссылку для покупателя ещё раз?"><span>Отправить страховой ещё раз</span><x-ui.icon name="send" class="size-5 shrink-0 text-ink-dim"/></button>
        @endif
    </div>
@else
    <div class="list mt-2">
        <div class="row justify-between">
            <span class="min-w-0"><span class="block">{{ $pass->name }}</span><span class="block text-sm text-ink-muted">заберёт {{ $pass->pickup_on->translatedFormat('j F') }}</span></span>
            <a href="tel:{{ preg_replace('/[^\d+]/', '', $pass->phone) }}" class="btn btn-round btn-quiet shrink-0" aria-label="Позвонить {{ $pass->phone }}"><x-ui.icon name="phone" class="size-5"/></a>
        </div>
        <div class="row justify-between"><span class="nums shrink-0 whitespace-nowrap">{{ $pass->phone }}</span><span class="min-w-0 truncate text-ink-muted">{{ $pass->email }}</span></div>
        @if ($pass->requestMessage)
            <button type="button" class="row justify-between w-full text-left" data-controller="emit" data-action="emit#send" data-emit-event-param="letters:open" data-emit-url-param="/cars/{{ $vehicle->id }}/letters">
                <span>Запрос страховой</span>
                <span class="flex shrink-0 items-center gap-1 text-sm text-ink-muted">{{ $pass->requestMessage->date_at?->translatedFormat('j M, H:i') }}<x-ui.icon name="chevron-right" class="size-4"/></span>
            </button>
        @endif
    </div>
    @if ($canManage)
        <div class="mt-3 flex flex-col gap-1">
            <button type="submit" form="buyer-confirm-form" class="btn btn-accent w-full" data-turbo-confirm="Страховая подтвердила покупателя {{ $pass->name }}?">Страховая подтвердила</button>
            <button type="button" class="btn btn-s btn-ghost self-center text-danger" data-controller="emit" data-action="emit#send" data-emit-event-param="buyer-reject:open">Не покупатель</button>
        </div>
    @endif
@endif
