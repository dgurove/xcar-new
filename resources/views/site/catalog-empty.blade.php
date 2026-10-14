{{-- Пусто: покупателю — фраза и контакт менеджера, остальным — «Сбросить фильтры». Общий кусок лент и плиток. --}}
@if ($buyer && ! $narrowed && ! $gallery)
    {{-- Покупателю пусто: одна фраза и контакт менеджера строкой. Видит всё (12.10.2026) — значит, у менеджера
         сейчас ничего нет; видит выбранные — менеджер ещё не открыл. Галерея от менеджера не зависит. --}}
    <x-ui.empty id="catalog-empty" class="pb-0">{{ $user->buyerSeesAll() ? 'Предложений в продаже сейчас нет' : ($manager ? $manager->shortName().' пока ничего вам не открыл' : 'Вам пока ничего не открыли') }}</x-ui.empty>
    @if ($manager)<div class="list mx-auto mt-4 max-w-sm"><x-ui.person-row :user="$manager" :size="44" class="row"/></div>@endif
@else
{{-- «Сбросить» снимает чипы пустыми значениями, иначе ListPrefs вернёт запомненный город; пилюля и поиск — тоже.
     Без предзагрузки: курсор над ссылкой стирал бы память фильтра. --}}
<x-ui.empty :href="$facets->resetUrl(['view' => null, 'q' => null])" :link="$narrowed ? 'Сбросить фильтры' : null" id="catalog-empty" data-turbo-prefetch="false">
    @if ($narrowed) По этим условиям ничего нет @elseif ($gallery) Пока пусто @else Предложений пока нет @endif
</x-ui.empty>
@endif
