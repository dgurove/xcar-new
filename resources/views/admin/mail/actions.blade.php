{{-- Кнопка заголовка почты «Написать»; на телефоне — кружком со значком, разделы «Работы» рядом не режутся. На «Из писем» писать нечего: у экрана одно дело — заводить. --}}
{{-- Новое письмо — окном писем, как ответ (28.10.2026): отдельной страницы редактора нет. --}}
{{-- В XCAR Mail (20.11.2026) шапки и таб-бара нет: «Из писем» с числом — здесь же, как в строке «Предложений» CRM, и аватар с
     меню (профиль, уведомления, тема, выход). --}}
@php $app = \App\Support\Surface::current() === \App\Support\Surface::Mail; @endphp
@if ($app && ! $forced)<a href="/offers/from-mail" class="btn btn-s btn-quiet relative shrink-0 rounded-full" aria-label="Из писем"><x-ui.icon name="flag" class="size-4"/><span class="hidden sm:inline">Из писем</span><x-ui.badge href="/offers/from-mail" :badges="\App\Support\Nav::badges(auth()->user())"/></a>@endif
@unless ($forced)<button type="button" class="btn btn-s btn-accent shrink-0 rounded-full" aria-label="Написать" data-controller="emit" data-action="emit#send" data-emit-event-param="letters:open" data-emit-url-param="{{ $base }}/new"><x-ui.icon name="edit" class="size-4"/><span class="hidden sm:inline">Написать</span></button>@endunless
@if ($app)<x-ui.me-menu/>@endif
