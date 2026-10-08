{{-- Окно писем — одно на страницу: шторка tall (на телефоне во весь экран, от 640 окно 48rem по центру)
     с фреймом letters-frame. Событие letters:open с url (x-mail.window-button, строка почты) грузит во фрейм
     ветку (/mail/{t}/window), письма ТС (/cars/{v}/letters) или редактор письма (/mail/new?…); url в пропе —
     открыть сразу (?window=). Ни блока сбоку, ни отдельной страницы ветки. Кнопки предмета в заголовок окна
     («✨ Распознать» у писем ТС и предложения) фрейм привозит шаблоном data-window-tools.
     Без пропа — адрес из session letters (приняли подтверждение в редакторе — черновик письма вендору после редиректа).
     letters:close — отправили письмо с then=close: окно закрывается, страница не перечитывается. --}}
@props(['url' => null, 'title' => 'Письма'])
@php $url ??= session('letters'); @endphp
<div data-controller="sheet window" data-action="letters:open@window->window#open letters:close@window->window#close" @if ($url) data-window-url-value="{{ $url }}" @endif class="contents">
    <x-ui.sheet id="letters" :title="$title" tall>
        <x-slot:tools><span class="contents" data-window-target="tools"></span></x-slot:tools>
        <template data-skeleton><x-ui.skeleton :rows="3"/></template>
        <turbo-frame id="letters-frame" target="_top" data-window-target="frame" @if ($url) src="{{ $url }}" @endif><x-ui.skeleton :rows="3"/></turbo-frame>
    </x-ui.sheet>
</div>
