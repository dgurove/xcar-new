{{-- Почта (17.11.2026, вид выбран владельцем по макетам): пилюли «Цепочки · Входящие · Отправленные · Архив» (открываются
     «Цепочки», 22.11.2026), на парковке первой — «Требуют внимания» (там задачи: осмотр, документы); в CRM её нет — только
     прочитанные и непрочитанные. «Цепочки», «Входящие» и «Отправленные» ($letters) — письма строками, как в Gmail: слева
     список (кто, тема, машина), справа открытое письмо (`Detail`, ключ — id письма, ↑/↓ листают); значок «Пачками» у
     «Проверить почту» складывает письма по цепочке ($stack, `admin.mail.letters`). «Архив» — дела карточками: машина,
     цепочка «Из писем», предложение или письмо без них, в карточке все письма дела (`admin.mail.list`).
     Верх — заголовок с «Написать» справа, пилюли, ящик (offer@ / deal@) чипом с меню, лупа, справа
     «Проверить почту», ниже чипы (вендор, непрочитанные, с файлами; во «Все делами» ещё смысл и «Без прочего»). Лупа ищет
     по ходу набора по всем письмам — мимо пилюли и чипов — и показывает дела; адрес не меняет. ?window=id — окно писем
     ветки сразу (ссылки из уведомлений).
     Этим же видом стоит «Из писем» ($forced): выборка «надо завести» вшита, пилюль нет и снять её нечем. --}}
@php
    $title = $forced ? 'Из писем' : 'Почта';
    $letters ??= false;
    $boxes = $forced ? [] : \App\Mail\Boxes::boxes($park ? \App\Mail\Scope::Park : \App\Mail\Scope::Offers);
    // offer@ только принимает — «Отправленных» у него нет.
    $pills = $mailbox?->reply_account_id ? array_diff_key($boxes, ['sent' => 1]) : $boxes;
    $boxUrl = fn (?string $slug) => request()->fullUrlWithQuery(['account' => $slug ?? '', 'page' => null, 'peek' => null, 'box' => $slug && $mailbox?->slug !== $slug && $box === 'sent' ? null : request()->query('box')]);
@endphp
{{-- XCAR Mail: «Из писем» — назад к «Почте» строкой над заголовком, шапки с «‹» там нет. --}}
@php $app = \App\Support\Surface::current() === \App\Support\Surface::Mail; @endphp
<x-ui.shell :title="$title" :count="$crm || $letters ? null : $threads->total()" :back="$app && $forced ? ['Почта', $base] : null">
    {{-- Ящик (13.10.2026, владелец: «письма только с offer и только с deal»): выбран — дела и письма только этого ящика. С 22.11.2026
         — у заголовка, а не в ряду фильтров (владелец: «выбор почты возле заголовка»), без выбора — deal@. --}}
    @if ($crm && ! $forced && $accounts->count() > 1)
        <x-slot:badge>
            <span class="contents" data-controller="menu">
                <button type="button" class="pill mail-box-pick" data-action="menu#toggle" aria-haspopup="menu" aria-controls="mail-box-menu">{{ $mailbox ? \Illuminate\Support\Str::before($mailbox->email, '@').'@' : 'Все ящики' }}<x-ui.icon name="chevron-down" class="ml-1 size-4"/></button>
                <span id="mail-box-menu" class="menu" popover data-menu-target="list" role="menu">
                    <a href="{{ $boxUrl(null) }}" class="menu-item" role="menuitem" data-turbo-action="replace" data-action="menu#close" @unless ($mailbox) aria-current="true" @endunless>Все ящики</a>
                    @foreach ($accounts as $account)
                        <a href="{{ $boxUrl($account->slug) }}" class="menu-item" role="menuitem" data-turbo-action="replace" data-action="menu#close" @if ($mailbox?->is($account)) aria-current="true" @endif>{{ $account->email }}</a>
                    @endforeach
                </span>
            </span>
        </x-slot:badge>
    @endif
    {{-- Кнопки стоят в строке заголовка и на телефоне: своей строки они не стоят. --}}
    @if (! $forced || $app)
        <x-slot:actions class="!ml-auto !w-auto">@include('admin.mail.actions')</x-slot:actions>
    @endif

    <x-ui.toolbar :class="$crm && ! $forced ? 'mt-4' : ''" :sort="$letters ? null : $sort" :pills="$pills" :pill="$forced ? '' : $box" pill-param="box" pill-home="chains" :pill-drop="['peek', 'stack']" :counts="$counts" :tones="['attention' => ! empty($counts['attention']) ? 'pill-count-urgent' : '']" :name="$forced ? 'from-mail' : 'mail'"
                  search="Найти письмо" search-target="#threads" :q="$q" :facets="$facets" fit>
        <x-slot:actions>
            {{-- «Пачками» — письма по цепочке, видно последнее (22.11.2026, владелец); включено — лаймовым, помнится у пилюли. --}}
            @if ($letters)
                <a href="{{ request()->fullUrlWithQuery(['stack' => $stack ? '0' : '1', 'page' => null, 'peek' => null]) }}" data-turbo-action="replace" @class(['btn btn-s btn-round shrink-0', 'btn-accent' => $stack, 'btn-quiet' => ! $stack])
                   aria-pressed="{{ $stack ? 'true' : 'false' }}" aria-label="Пачками" title="{{ $stack ? 'Пачками: видно последнее письмо цепочки' : 'Сложить в пачки по цепочкам' }}"><x-ui.icon name="layers" class="size-5"/></a>
            @endif
            {{-- «Проверить почту» — ящики забираются тут же, тост — сколько пришло. --}}
            <form method="post" action="{{ $base }}/sync" class="contents">@csrf<button class="btn btn-s btn-quiet btn-round shrink-0" aria-label="Проверить почту" title="Проверить почту"><x-ui.icon name="refresh" class="size-5"/></button></form>
        </x-slot:actions>
    </x-ui.toolbar>

    @if ($accounts->isEmpty())
        @if ($crm)<x-ui.empty class="mt-6" href="/settings/mailboxes/new" link="Завести ящик">Ящиков ещё нет</x-ui.empty>@else<x-ui.empty class="mt-6">Ящиков ещё нет</x-ui.empty>@endif
    @elseif ($letters)
        {{-- Список слева, письмо справа: та же связка, что `x-ui.shell :detail`, но под шапкой — шапка во всю ширину. --}}
        <div class="split split--mail mt-4" data-controller="detail" data-action="turbo:frame-load->detail#loaded keydown@window->detail#key click->detail#tap" @if ($detail->open()) data-open @endif>
            <div class="split-main"><div id="threads" data-controller="endless">@include('admin.mail.letters')</div></div>
            {{ $detail->html() }}
        </div>
    @else
        <div class="mt-4" id="threads" data-controller="endless">@include('admin.mail.list')</div>
    @endif
    <x-mail.window :url="$window ?? null"/>
</x-ui.shell>
