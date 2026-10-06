{{-- Заголовок столбца таблицы. С сортировкой списка (App\Support\Sort), где это поле есть, — ссылка: первое нажатие
     по убыванию, второе по возрастанию, третье возвращает умолчание (`Sort::next`); у текущего — стрелка направления.
     Без сортировки или поля — обычный <th>. Предзагрузки нет: ListPrefs запомнил бы сортировку от наведения. --}}
@props(['sort' => null, 'key' => null])
<th {{ $attributes }}>
    @if ($sort && $key && $sort->has($key))
        <a href="{{ \App\Support\Sort::url($sort->next($key)) }}" class="th-sort" data-turbo-action="replace" data-turbo-prefetch="false" @if ($sort->key === $key) aria-current="true" @endif>{{ $slot }}@if ($sort->key === $key)<x-ui.icon :name="$sort->desc ? 'arrow-down' : 'arrow-up'" class="size-3"/>@endif</a>
    @else
        {{ $slot }}
    @endif
</th>
