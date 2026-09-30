{{-- Ссылки списком: первая строка — «Новая ссылка» (шторка с формой), дальше действующие,
     ниже — сработавшие и выключенные. Строка — кому и что о ней важно (≤3 чипов), нажатие —
     шторка: адрес, кто пришёл, выключатель. Один и тот же список в кабинете и в CRM. --}}
@props(['invites', 'admin' => false, 'managers' => collect(), 'groups' => collect(), 'fresh' => null])
@php
    use App\Http\Cabinet\InviteController;
    $me = auth()->user();
    $base = InviteController::base();
    [$live, $past] = $invites->partition(fn ($i) => $i->isActive());
@endphp
<div class="list">
    <div data-controller="sheet" class="contents">
        <button type="button" class="row w-full text-left transition-colors hover:bg-hover" data-action="sheet#open">
            <x-ui.row-icon name="plus" tone="accent"/>
            <span class="min-w-0 flex-1 font-medium">Новая ссылка</span>
        </button>
        <x-ui.sheet id="invite-new" title="Новая ссылка" :open="$errors->any()">
            <x-invites.form :action="$base" :admin="$admin" :managers="$managers" :groups="$groups"/>
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
