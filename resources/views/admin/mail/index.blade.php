{{-- Почта: Входящие · Ждут ответа · Прочее · Отправленные · Архив (ветка там, где её последнее письмо, как в Gmail).
     Во «Входящих» секции: ТС (CRM — предложения) и кандидаты «Из писем» («Завести ›»), письма вендоров без тождества —
     последней секцией «Без тождества»; автоответы, рассылки и не-вендоры — в «Прочее» («Всё в архив»). Верх — заголовок
     с «Написать» справа и одна строка: поиск, пилюли, сортировка и фильтры круглыми кнопками. Поиск ищет по ходу набора
     внутри текущей пилюли и фильтров и адрес не меняет. Любая строка открывает окно писем ветки (x-mail.window);
     ?window=id — открыть окно сразу (ссылки из уведомлений). --}}
<x-ui.shell title="Почта" :count="$crm ? null : $threads->total()" :heading="$crm ? false : 'Почта'">
    {{-- Кнопка стоит в строке заголовка и на телефоне: своей строки она не стоит. --}}
    <x-slot:actions class="!ml-auto !w-auto">
        @if ($box === 'other' && $threads->total())
            <form method="post" action="{{ $base }}/archive-other" class="contents" data-turbo-confirm="Убрать всё «Прочее» в архив?">@csrf<button class="btn btn-s btn-quiet shrink-0 rounded-full"><x-ui.icon name="archive" class="size-4"/>Всё в архив</button></form>
        @endif
        <a href="{{ $base }}/new" class="btn btn-s btn-accent shrink-0 rounded-full"><x-ui.icon name="edit" class="size-4"/>Написать</a>
    </x-slot:actions>

    @if ($crm)<x-admin.work-titles current="mail" :count="$threads->total()"/>@endif

    <x-ui.toolbar :class="$crm ? 'mt-4' : ''" :sorts="\App\Http\Admin\MailController::SORTS" :sort="$sort" :pills="\App\Http\Admin\MailController::BOXES" :pill="$box" pill-param="box" :counts="$counts" :tones="['waiting' => ! empty($counts['waiting']) ? 'pill-urgent' : '']" name="mail"
                  search="Найти письмо" search-target="#threads" :q="$q">
        <x-slot:filters>
            <select name="vendor" class="field-input"><option value="">Все вендоры</option>@foreach ($vendors as $id => $name)<option value="{{ $id }}" @selected(($filter['vendor'] ?? null) === $id)>{{ $name }}</option>@endforeach</select>
            <select name="intent" class="field-input"><option value="">Любой смысл письма</option>@foreach (\App\Mail\Extraction\Intent::cases() as $i)@if ($i->short())<option value="{{ $i->value }}" @selected(($filter['intent'] ?? null) === $i->value)>{{ $i->title() }}</option>@endif @endforeach</select>
            <x-ui.check name="unread" :checked="! empty($filter['unread'])">Только непрочитанные</x-ui.check>
            <x-ui.check name="files" :checked="! empty($filter['files'])">С файлами</x-ui.check>
        </x-slot:filters>
    </x-ui.toolbar>

    @if ($accounts->isEmpty())
        @if ($crm)<x-ui.empty class="mt-6" href="/settings/mailboxes/new" link="Завести ящик">Ящиков ещё нет</x-ui.empty>@else<x-ui.empty class="mt-6">Ящиков ещё нет</x-ui.empty>@endif
    @else
        <div class="mt-4" id="threads" data-controller="endless">@include('admin.mail.list')</div>
    @endif
    <x-mail.window :url="$window ?? null"/>
</x-ui.shell>
