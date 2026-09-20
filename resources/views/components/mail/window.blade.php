{{-- Окно писем — одно на страницу: шторка tall (на телефоне во весь экран, от 640 окно 48rem по центру)
     с фреймом letters-frame. Событие letters:open с url (x-mail.window-button, строка почты) грузит во фрейм
     ветку (/mail/{t}/window), письма ТС (/cars/{v}/letters) или редактор письма (/mail/new?…); url в пропе —
     открыть сразу (?window=). Ни блока сбоку, ни отдельной страницы ветки. --}}
@props(['url' => null, 'title' => 'Письма'])
<div data-controller="sheet window" data-action="letters:open@window->window#open" @if ($url) data-window-url-value="{{ $url }}" @endif class="contents">
    <x-ui.sheet id="letters" :title="$title" tall>
        <template data-skeleton><x-ui.skeleton :rows="3"/></template>
        <turbo-frame id="letters-frame" target="_top" data-window-target="frame" @if ($url) src="{{ $url }}" @endif><x-ui.skeleton :rows="3"/></turbo-frame>
    </x-ui.sheet>
</div>
