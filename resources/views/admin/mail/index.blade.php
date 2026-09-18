@php $crm = $base !== '/mail'; @endphp
<x-ui.shell title="Почта" :heading="false">
    @if ($crm)
        <x-admin.work-titles current="mail" :count="$threads->total()"/>
    @else
        <x-ui.section-title level="h1" :count="$threads->total()">Почта</x-ui.section-title>
    @endif

    <x-ui.toolbar class="mt-5" :pills="\App\Http\Admin\MailController::PRESETS" :pill="$preset" pill-param="preset" :counts="['unread' => $unread]" :hidden="['yashchik' => $slug]" name="mail">
        <x-slot:extra>
            <a href="{{ $base }}/new{{ $slug ? '?yashchik='.$slug : '' }}" class="btn btn-s btn-accent shrink-0 rounded-full"><x-ui.icon name="edit" class="size-4"/><span class="hidden sm:inline">Написать</span></a>
        </x-slot:extra>
        <x-slot:filters>
            <input name="q" value="{{ $q }}" placeholder="Тема, отправитель" class="field-input field-s">
            @if ($accounts->count() > 1)
                <select name="yashchik" class="field-input field-s" aria-label="Ящик">
                    <option value="">Все ящики</option>
                    @foreach ($accounts as $account)<option value="{{ $account->slug }}" @selected($slug === $account->slug)>{{ $account->title }}</option>@endforeach
                </select>
            @endif
        </x-slot:filters>
    </x-ui.toolbar>

    @if ($accounts->isEmpty())
        <x-ui.empty class="mt-6" href="/settings/mailboxes/new" link="Завести ящик">Ящиков ещё нет</x-ui.empty>
    @elseif ($threads->isEmpty())
        <x-ui.empty class="mt-6">Писем нет</x-ui.empty>
    @else
        <div class="mt-6 flex flex-col gap-2" id="threads">
            @foreach ($threads as $thread)
                @include('admin.mail.thread-row')
            @endforeach
        </div>
        <div class="mt-8"><x-ui.pager :of="$threads"/></div>
    @endif
</x-ui.shell>
