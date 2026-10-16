{{-- Кнопка заголовка почты «Написать»; на телефоне — кружком со значком, разделы «Работы» рядом не режутся. На «Из писем» писать нечего: у экрана одно дело — заводить. --}}
@unless ($forced)<a href="{{ $base }}/new" class="btn btn-s btn-accent shrink-0 rounded-full" aria-label="Написать"><x-ui.icon name="edit" class="size-4"/><span class="hidden sm:inline">Написать</span></a>@endunless
