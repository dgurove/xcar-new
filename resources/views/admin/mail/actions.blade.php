{{-- Кнопки заголовка почты: «Всё в архив» у «Прочего» и «Написать». На «Из писем» писать нечего: у экрана одно дело — заводить. --}}
@if ($box === 'other' && $threads->total())
    <form method="post" action="{{ $base }}/archive-other" class="contents" data-turbo-confirm="Убрать всё «Прочее» в архив?">@csrf<button class="btn btn-s btn-quiet shrink-0 rounded-full"><x-ui.icon name="archive" class="size-4"/>Всё в архив</button></form>
@endif
@unless ($forced)<a href="{{ $base }}/new" class="btn btn-s btn-accent shrink-0 rounded-full"><x-ui.icon name="edit" class="size-4"/>Написать</a>@endunless
