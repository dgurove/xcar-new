{{-- Почта — рабочий список дел: Требуют внимания · Все · Прочее · Отправленные · Архив. Дело — машина, цепочка
     «Из писем» или предложение; секция стоит целиком в любой пилюле, потому что действие требуется от дела, а не
     от одного письма. «Требуют внимания» — завести цепочку, ответить на вопрос, разобрать заявку без тождества.
     Автоответы, рассылки, не-вендоры и бухгалтерия без машины — в «Прочее» («Всё в архив»). Верх — заголовок с
     «Написать» справа, пилюли, сортировка и лупа, ниже чипы (вендор, смысл, непрочитанные, с файлами). Лупа ищет по
     ходу набора по всем письмам — мимо пилюли и чипов — и адрес не меняет. Любая строка открывает окно писем ветки
     (x-mail.window); ?window=id — открыть окно сразу (ссылки из уведомлений).
     Этим же видом стоит «Из писем» ($forced): выборка «надо завести» вшита, пилюль нет и снять её нечем. --}}
@php $title = $forced ? 'Из писем' : 'Почта'; @endphp
<x-ui.shell :title="$title" :count="$crm && ! $forced ? null : $threads->total()" :heading="$crm && ! $forced ? false : $title">
    {{-- Кнопки стоят в строке заголовка и на телефоне: своей строки они не стоят. В CRM заголовок — строка
         разделов «Работы» (x-admin.work-titles), кнопки справа в ней же. --}}
    @if ($crm && ! $forced)
        <div class="flex items-center gap-3">
            <div class="min-w-0 flex-1"><x-admin.work-titles current="mail" :count="$threads->total()"/></div>
            @include('admin.mail.actions')
        </div>
    @elseif (! $forced)
        <x-slot:actions class="!ml-auto !w-auto">@include('admin.mail.actions')</x-slot:actions>
    @endif

    <x-ui.toolbar :class="$crm && ! $forced ? 'mt-4' : ''" :sorts="\App\Mail\Boxes::SORTS" :sort="$sort" :pills="$forced ? [] : \App\Mail\Boxes::BOXES" :pill="$forced ? '' : $box" pill-param="box" pill-home="all" :counts="$counts" :tones="['attention' => ! empty($counts['attention']) ? 'pill-urgent' : '']" :name="$forced ? 'from-mail' : 'mail'"
                  search="Найти письмо" search-target="#threads" :q="$q" :facets="$facets">
        {{-- «Проверить почту» — в ряду кнопок списка справа: ящики забираются тут же, тост — сколько пришло. В строке
             заголовка она на телефоне висела одна, а ряд кнопок справа пустовал. --}}
        <x-slot:actions><form method="post" action="{{ $base }}/sync" class="contents">@csrf<button class="btn btn-s btn-quiet btn-round shrink-0" aria-label="Проверить почту" title="Проверить почту"><x-ui.icon name="refresh" class="size-5"/></button></form></x-slot:actions>
    </x-ui.toolbar>

    @if ($accounts->isEmpty())
        @if ($crm)<x-ui.empty class="mt-6" href="/settings/mailboxes/new" link="Завести ящик">Ящиков ещё нет</x-ui.empty>@else<x-ui.empty class="mt-6">Ящиков ещё нет</x-ui.empty>@endif
    @else
        <div class="mt-4" id="threads" data-controller="endless">@include('admin.mail.list')</div>
    @endif
    <x-mail.window :url="$window ?? null"/>
</x-ui.shell>
