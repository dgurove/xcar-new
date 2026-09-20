{{-- Почта: таблица по умолчанию, строки со свайпом «прочитано» — по выбору; любая строка открывает окно писем ветки
     (x-mail.window), страницы ветки нет; ?window=id — открыть окно сразу (ссылки из уведомлений). --}}
@php use App\Support\ListView; $crm = $base !== '/mail'; @endphp
<x-ui.shell title="Почта" :heading="false">
    @if ($crm)
        <x-admin.work-titles current="mail" :count="$threads->total()"/>
    @else
        <x-ui.section-title level="h1" :count="$threads->total()">Почта</x-ui.section-title>
    @endif

    <x-ui.toolbar class="mt-5" :sorts="\App\Http\Admin\MailController::SORTS" :sort="$sort" :pills="$presets" :pill="$preset" pill-param="preset" :counts="['unread' => $unread]" :hidden="array_filter(['account' => $slug, 'car' => request('car'), ListView::PARAM => request(ListView::PARAM)])" name="mail">
        <x-slot:extra>
            <x-ui.view-switch :views="[ListView::TABLE, ListView::LIST]" :current="$view"/>
            <a href="{{ $base }}/new{{ $slug ? '?account='.$slug : '' }}" class="btn btn-s btn-accent shrink-0 rounded-full"><x-ui.icon name="edit" class="size-4"/><span class="hidden sm:inline">Написать</span></a>
        </x-slot:extra>
        <x-slot:filters>
            <input name="q" value="{{ $q }}" placeholder="Тема, отправитель" class="field-input field-s">
            @if ($accounts->count() > 1)
                <select name="account" class="field-input field-s" aria-label="Ящик">
                    <option value="">Все ящики</option>
                    @foreach ($accounts as $account)<option value="{{ $account->slug }}" @selected($slug === $account->slug)>{{ $account->title }}</option>@endforeach
                </select>
            @endif
        </x-slot:filters>
    </x-ui.toolbar>
    @if ($car)
        <div class="mt-4 flex flex-wrap items-center gap-1.5">
            <a href="{{ $crm ? '/offers' : '/cars/'.$car->id }}" class="chip"><x-ui.icon name="car" class="size-3.5"/>{{ $car->titleWithYear() }}@if ($car->ref && $car->brand_id) <span class="nums text-ink-muted">{{ $car->ref }}</span>@endif</a>
            <a href="{{ request()->fullUrlWithQuery(['car' => null, 'page' => null]) }}" class="chip" aria-label="Все письма"><x-ui.icon name="x" class="size-3.5"/></a>
        </div>
    @endif

    @if ($accounts->isEmpty())
        @if ($crm)<x-ui.empty class="mt-6" href="/settings/mailboxes/new" link="Завести ящик">Ящиков ещё нет</x-ui.empty>@else<x-ui.empty class="mt-6">Ящиков ещё нет</x-ui.empty>@endif
    @elseif ($threads->isEmpty())
        <x-ui.empty class="mt-6">Писем нет</x-ui.empty>
    @elseif ($view === ListView::TABLE)
        <x-ui.table id="threads" class="mt-6">
            <x-slot:head><tr><th class="hidden sm:table-cell">От кого</th><th class="grow">Тема</th><th class="hidden sm:table-cell">{{ $crm ? 'Предложение' : 'ТС' }}</th><th class="w-10 pl-0"></th><th class="num">Когда</th></tr></x-slot:head>
            @foreach ($threads as $thread)<x-mail.thread-row :thread="$thread" :base="$base" :accounts="$accounts" :slug="$slug"/>@endforeach
        </x-ui.table>
        @if ($threads->hasPages())<div class="mt-8"><x-ui.pager :of="$threads"/></div>@endif
    @else
        <div class="mt-6 flex flex-col gap-2" id="threads">
            @foreach ($threads as $thread)
                @include('admin.mail.thread-row')
            @endforeach
        </div>
        <div class="mt-8"><x-ui.pager :of="$threads" :sizes="ListView::PER_ROWS"/></div>
    @endif
    <x-mail.window :url="$window ?? null"/>
</x-ui.shell>
