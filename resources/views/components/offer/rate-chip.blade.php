{{-- «Оценить» у неоценённого черновика (админу): чип-кнопка там, где должна стоять цена продажи — в таблице, плитках и
     строках. Ведёт в карточку таблицы на этой строке (цена → «В продажу», и сразу следующий неоценённый); список, пресет
     и сортировка остаются те же, вид — таблица, страницы нет: таблица целиком на одной. Предзагрузка ни к чему. --}}
@props(['offer'])
@php $href = '/?'.http_build_query(array_filter(array_merge(request()->query(), ['vid' => 'table', 'peek' => $offer->number, 'page' => null]), fn ($v) => is_string($v) || is_int($v) ? $v !== '' : false)); @endphp
<a href="{{ $href }}" {{ $attributes->class(['pill pill-accent !min-h-6 !py-0 !px-3 text-sm']) }} data-turbo-action="replace" data-turbo-prefetch="false">Оценить</a>
