{{-- Шапка таблицы из описания (`App\Support\Table\Table`): столбцы и их классы — те же, что у строк. sort — заголовок
     сортирует нажатием (`x-ui.th`), если у столбца есть ключ сортировки. --}}
@props(['table', 'sort' => null])
<tr>
    @foreach ($table->columns as $column)
        <x-ui.th :sort="$sort" :key="$column->sort" @class([$column->classes(head: true), 'title-cap' => $column->grow && $column->cap])>{{ $column->title }}</x-ui.th>
    @endforeach
</tr>
