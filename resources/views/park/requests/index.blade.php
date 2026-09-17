@php use App\Park\RequestType; @endphp
<x-ui.shell title="Заявки" :count="$requests->total()">
    <x-ui.toolbar :sorts="\App\Http\Park\RequestController::SORTS" :sort="$sort" :pills="$presets" :pill="$preset" pill-param="preset" :counts="$counts" :hidden="['gotovye' => $done ? 1 : null]" name="requests">
        <x-slot:extra>
            <x-ui.pill :href="request()->fullUrlWithQuery(['gotovye' => $done ? null : 1, 'page' => null])" :current="$done">Готовые</x-ui.pill>
            <a href="/requests/new" class="btn btn-s btn-accent shrink-0 rounded-full"><x-ui.icon name="plus" class="size-4"/><span class="hidden sm:inline">Заявка</span></a>
            <a href="/requests/from-mail" class="btn btn-s btn-quiet relative shrink-0 rounded-full" aria-label="Из писем"><x-ui.icon name="mail" class="size-4"/><span class="hidden sm:inline">Из писем</span><x-ui.badge href="/requests/from-mail" :badges="\App\Support\Nav::badges(auth()->user())"/></a>
        </x-slot:extra>
    </x-ui.toolbar>
    @if ($requests->isEmpty())
        <x-ui.empty class="mt-6">{{ $done ? 'Готовых нет.' : 'Всё сделано.' }}</x-ui.empty>
    @else
        <div class="mt-6 flex flex-col gap-2">
            @foreach ($requests as $r)
                <x-park.vehicle-row :vehicle="$r->vehicle" :href="'/requests/'.$r->id">
                    <x-ui.pill :tone="$r->isOverdue() ? 'danger' : ($r->isOpen() ? 'soft' : 'closed')" class="!min-h-0 !py-1 text-xs">{{ $r->type->label() }}@if ($r->type === RequestType::Move && $r->yard) <x-ui.place>{{ $r->yard->name }}</x-ui.place>@endif</x-ui.pill>
                    @if ($r->planned_at)<span class="chip {{ $r->isOverdue() ? 'text-danger' : '' }}">{{ $r->planned_at->translatedFormat('j M, H:i') }}</span>@endif
                    @if (!$r->isOpen())<span class="chip">{{ $r->state->label() }}</span>@endif
                    @if ($r->contact)<span class="text-sm text-ink-muted">{{ $r->contact }}</span>@endif
                </x-park.vehicle-row>
            @endforeach
        </div>
        <div class="mt-8">{{ $requests->links() }}</div>
    @endif
</x-ui.shell>
