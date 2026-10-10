{{-- Поиск страницей: тот же кусок, что в выпадающем списке шапки, — открыли `/search?q=` руками, обновили или нажали
     Enter до выдачи. Поле живое: тот же global_search_controller, выдача подменяется во фрейме. --}}
<x-ui.shell title="Поиск" narrow>
    <div data-controller="global-search" data-global-search-hotkey-value="false" data-action="click->global-search#picked">
        <x-search.field :hint="\App\Search\Search::hint()" :value="$q" autofocus/>
        <turbo-frame id="global-search-page" target="_top" class="mt-4 block" data-global-search-target="frame">@include('search.results', ['groups' => $groups, 'q' => $q])</turbo-frame>
    </div>
</x-ui.shell>
