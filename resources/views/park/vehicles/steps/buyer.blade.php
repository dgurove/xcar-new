{{-- Шаг «Покупатель» (выдача по QR). Анкеты нет — ссылка для покупателя: скопировать или отправить страховой ещё раз.
     Анкета есть — кто и когда заберёт, письмо страховой с запросом, «Страховая подтвердила» и «Не покупатель».
     Формы — вне act-form (show.blade.php), кнопки ссылаются на них атрибутом form. --}}
@if (! $pass)
    <div class="mt-2 flex flex-wrap items-center gap-2">
        <button type="button" class="btn btn-s btn-quiet" data-controller="copy" data-copy-text-value="{{ $vehicle->pickupUrl() }}" data-copy-done-value="Ссылка в буфере" data-action="copy#copy:prevent"><x-ui.icon name="copy" class="size-4"/>Скопировать ссылку для покупателя</button>
        @if ($canManage)<button type="submit" form="pickup-link-form" class="btn btn-s btn-quiet" data-turbo-confirm="Отправить страховой ссылку для покупателя ещё раз?">Отправить страховой ещё раз</button>@endif
    </div>
@else
    <dl class="mt-2 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 text-sm">
        <dt class="text-ink-muted">ФИО</dt><dd>{{ $pass->name }}</dd>
        <dt class="text-ink-muted">Телефон</dt><dd><a href="tel:{{ preg_replace('/[^\d+]/', '', $pass->phone) }}" class="nums text-accent-text">{{ $pass->phone }}</a></dd>
        <dt class="text-ink-muted">Почта</dt><dd class="truncate">{{ $pass->email }}</dd>
        <dt class="text-ink-muted">Заберёт</dt><dd>{{ $pass->pickup_on->translatedFormat('j F, D') }}</dd>
        @if ($pass->requestMessage)<dt class="text-ink-muted">Страховой</dt><dd>запрос отправлен {{ $pass->requestMessage->date_at?->translatedFormat('j M, H:i') }}</dd>@endif
    </dl>
    @if ($canManage)
        <div class="mt-3 flex flex-wrap items-center gap-2">
            <button type="submit" form="buyer-confirm-form" class="btn btn-s btn-accent" data-turbo-confirm="Страховая подтвердила, что {{ $pass->name }} — покупатель?">Страховая подтвердила</button>
            <x-mail.window-button :url="'/cars/'.$vehicle->id.'/letters'" label="Письма" class="btn-s btn-quiet"/>
            <button type="button" class="btn btn-s btn-quiet" data-controller="emit" data-action="emit#send" data-emit-event-param="buyer-reject:open">Не покупатель</button>
        </div>
    @endif
@endif
