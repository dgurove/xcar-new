{{-- Окно писем — одно на страницу: шторка tall (на телефоне во весь экран, от 640 окно 48rem по центру)
     с фреймом letters-frame. Событие letters:open с url (x-mail.window-button, строка почты) грузит во фрейм
     ветку (/mail/{t}/window), письма ТС (/cars/{v}/letters) или редактор письма (/mail/new?…); url в пропе —
     открыть сразу (?window=). Ни блока сбоку, ни отдельной страницы ветки. Кнопки предмета в заголовок окна
     («✨ Распознать» у писем ТС и предложения) фрейм привозит шаблоном data-window-tools.
     Без пропа — адрес из session letters (приняли подтверждение в редакторе — черновик письма вендору после редиректа).
     letters:close — отправили письмо с then=close: окно закрывается, страница не перечитывается. --}}
@props(['url' => null, 'title' => 'Письма'])
@php
    // ?letters= — открытые на телефоне письма дела (17.11.2026, `window_controller`): приложение выгрузили из памяти или страницу
    // перечитали — письмо снова открыто. Только адрес писем машины, предложения или очереди своего хоста.
    $kept = request()->query('letters');
    $kept = is_string($kept) && preg_match('#^/(offers|cars)/\d+/letters(\?[\w=&%.-]*)?$|^/[\w/-]+/\d+/letters(\?[\w=&%.-]*)?$#', $kept) ? $kept : null;
    // Метка `letters` забирается (`pull`), а не читается: прочитанная, она оставалась во flash, а фоновые запросы
    // (`KeepFlashInBackground` продлевает flash) возвращали её снова — письмо по давней машине всплывало на любой странице
    // (21.11.2026). Фоновый запрос (предзагрузка страницы) метку не трогает: её заберёт настоящий переход.
    $background = \App\Http\Middleware\KeepFlashInBackground::background(request());
    $url ??= ($background ? null : session()->pull('letters')) ?? \App\Mail\LetterPrompt::take() ?? $kept;
@endphp
<div data-controller="sheet window" data-action="letters:open@window->window#open letters:close@window->window#close" @if ($url) data-window-url-value="{{ $url }}" @endif class="contents">
    <x-ui.sheet id="letters" :title="$title" tall>
        <x-slot:tools><span class="contents" data-window-target="tools"></span></x-slot:tools>
        <template data-skeleton><x-ui.skeleton :rows="3"/></template>
        <turbo-frame id="letters-frame" target="_top" data-window-target="frame" @if ($url) src="{{ $url }}" @endif><x-ui.skeleton :rows="3"/></turbo-frame>
    </x-ui.sheet>
</div>
{{-- Шторка «Вендор письма» — там же, где окно писем: одна на страницу, её открывает «Выбрать вендора» (x-mail.vendor-pick). --}}
@php $vendorScope = \App\Support\Surface::current() === \App\Support\Surface::Park ? \App\Mail\Scope::Park : \App\Mail\Scope::Offers; @endphp
@if (\App\Mail\VendorPick::allowed(auth()->user(), $vendorScope))
    {{-- В CRM — по первому требованию (17.11.2026: список всех вендоров, 12 КБ и запрос, ехал на каждую страницу с окном писем);
         после ошибки формы — сразу: введённое стоит в шторке. --}}
    @if ($vendorScope === \App\Mail\Scope::Offers && ! $errors->any() && ! session()->hasOldInput())
        <div class="contents" data-lazy-sheets="/work/mail/vendor-sheet" data-lazy-sheets-for="vendor-pick:open"></div>
    @else
        <x-mail.vendor-sheet :park="$vendorScope === \App\Mail\Scope::Park"/>
    @endif
@endif
