{{-- Рабочий стол стоянки: числа сверху, секции по делу дня, пустые не рисуются. День и «мои» — в адресе. --}}
@php use App\Park\{RequestType, RequestState, Vehicle}; $q = fn (array $p) => request()->fullUrlWithQuery($p); @endphp
<x-ui.shell title="Сегодня" :phone-heading="false">
    <div class="flex flex-wrap items-center gap-1.5">
        @foreach ($days as $key => $label)
            <x-ui.pill :href="$q(['day' => $key === 'today' ? null : $key])" :current="$day === $key">{{ $label }}</x-ui.pill>
        @endforeach
        <x-ui.pill :href="$q(['mine' => $mine ? null : 1])" :current="$mine" class="ml-auto">Мои</x-ui.pill>
        <a href="/requests" class="btn btn-s btn-quiet rounded-full">Все заявки</a>
        <a href="/requests/new" class="btn btn-s btn-accent rounded-full"><x-ui.icon name="plus" class="size-4"/><span class="hidden sm:inline">Заявка</span></a>
        <a href="/requests/from-mail" class="btn btn-s btn-quiet relative rounded-full" aria-label="Из писем"><x-ui.icon name="mail" class="size-4"/><x-ui.badge href="/requests/from-mail" :badges="\App\Support\Nav::badges(auth()->user())"/></a>
    </div>
    <div class="mt-5 grid grid-cols-2 gap-2 sm:grid-cols-4">
        @foreach ($stats as $label => $value)<x-ui.stat :value="$value" :label="$label"/>@endforeach
    </div>
    @if (!$sections)
        <x-ui.empty class="mt-6">Всё сделано</x-ui.empty>
    @endif
    @foreach ($sections as [$title, $tone, $items, $kind])
        <section class="mt-8">
            <h2 class="text-xl {{ $tone === 'danger' ? 'text-danger' : ($tone === 'urgent' ? 'text-urgent' : '') }}">{{ $title }} <span class="nums text-ink-dim">{{ $items->count() }}</span></h2>
            <div class="mt-3 flex flex-col gap-2">
                @foreach ($items as $item)
                    @if ($item instanceof Vehicle)
                        <x-park.vehicle-row :vehicle="$item">
                            <x-park.state :vehicle="$item"/>
                            @if ($kind === 'vehicles' && $item->docsPending())@foreach ($item->docs->filter(fn ($d) => $d->isOut() && !$d->isDone()) as $d)<span class="chip">{{ $d->kind->label() }}</span>@endforeach @endif
                            @if ($item->offer)<span class="chip nums">№ {{ $item->offer->number }}</span>@endif
                        </x-park.vehicle-row>
                    @else
                        @php $r = $item; @endphp
                        <x-park.vehicle-row :vehicle="$r->vehicle" :href="'/requests/'.$r->id">
                            <x-ui.pill :tone="$r->isOverdue() ? 'danger' : 'soft'" class="!min-h-0 !py-1 text-xs">{{ $r->type->label() }}@if ($r->state !== RequestState::New) <span class="font-normal">{{ mb_strtolower($r->state->label()) }}</span>@endif</x-ui.pill>
                            @if ($r->planned_at)<span class="chip nums {{ $r->isOverdue() ? 'text-danger' : '' }}">{{ $r->planned_at->translatedFormat('j M, H:i') }}</span>@endif
                            @if ($r->isTow() && $r->from_address)<x-ui.place class="chip">{{ $r->from_address }}</x-ui.place>@endif
                            @if ($r->isTow() && $r->contact_phone)<span class="chip nums"><x-ui.icon name="phone" class="size-3.5"/>{{ $r->contact_phone }}</span>@endif
                            @if ($r->carrier)<span class="chip">{{ $r->carrier }}</span>@endif
                            @if ($r->assignee)<x-ui.person :user="$r->assignee"/>@endif
                        </x-park.vehicle-row>
                    @endif
                @endforeach
            </div>
        </section>
    @endforeach
</x-ui.shell>
