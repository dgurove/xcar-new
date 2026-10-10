{{-- Поиск страницей: тот же кусок, что в выпадающем списке шапки, — открыли `/search?q=` руками или обновили. --}}
<x-ui.shell title="Поиск">
    <form method="get" action="/search" class="mb-4" role="search">
        <input type="search" name="q" value="{{ $q }}" class="field-input" placeholder="Номер, VIN, госномер, марка, имя" enterkeyhint="search" autocomplete="off" aria-label="Поиск">
    </form>
    @include('search.results', ['groups' => $groups, 'q' => $q])
</x-ui.shell>
