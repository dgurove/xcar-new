{{-- «Ссылки» экрана «Пользователи» на парковке: первой строкой новая (управляющему или приёмщику, одноразовая со сроком,
     «Добавить» в тулбаре открывает её сразу), дальше живые и сработавшие общим `invites.row`. --}}
@php
    $base = \App\Http\Cabinet\InviteController::base();
    $admin = false;
    [$live, $past] = $invites->partition(fn ($i) => $i->isActive());
@endphp
<div class="list">
    <div data-controller="sheet" class="contents">
        <button type="button" class="row w-full text-left transition-colors hover:bg-hover" data-action="sheet#open">
            <x-ui.row-icon name="plus" tone="accent"/>
            <span class="min-w-0 flex-1 font-medium">Новая ссылка</span>
        </button>
        <x-ui.sheet id="invite-new" title="Новая ссылка" :open="request()->boolean('add')">
            <form method="post" action="/users/invites" class="flex flex-col gap-3">
                @csrf
                <div class="fields">
                    <x-ui.field name="expires_at" label="Действует до" type="datetime-local" :value="now()->addDays(3)->format('Y-m-d\TH:i')" :min="now()->format('Y-m-d\TH:i')"/>
                </div>
                <x-park.access-fields/>
                <x-ui.sheet-foot>Создать ссылку</x-ui.sheet-foot>
            </form>
        </x-ui.sheet>
    </div>
    @foreach ($live as $invite)
        @include('invites.row')
    @endforeach
</div>
@if ($past->isNotEmpty())
    <section>
        <h2 class="list-head">Сработали и выключенные</h2>
        <div class="list">
            @foreach ($past as $invite)
                @include('invites.row')
            @endforeach
        </div>
    </section>
@endif
