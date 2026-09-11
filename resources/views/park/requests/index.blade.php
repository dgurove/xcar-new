@php use App\Park\RequestType; @endphp
<x-ui.shell title="Заявки" :wide="true">
    <div class="mb-4 flex flex-col gap-3">
        <div class="flex items-center gap-3">
            <x-ui.sort :items="\App\Http\Park\RequestController::SORTS" :current="$sort"/>
            <a href="{{ request()->fullUrlWithQuery(['gotovye' => $done ? null : 1, 'page' => null]) }}" class="chip {{ $done ? 'bg-chrome text-white dark:bg-white dark:text-chrome' : '' }}">Готовые</a>
            <a href="/zayavki/novaya" class="btn btn-primary btn-sm ml-auto shrink-0"><x-ui.icon name="plus" class="size-4"/> Заявка</a>
        </div>
        <x-ui.presets :items="$presets" :current="$preset" :counts="$counts"/>
    </div>
    @if ($requests->isEmpty())
        <div class="py-24 text-center text-ink-muted">{{ $done ? 'Готовых нет' : 'Всё сделано' }}</div>
    @else
        <div class="flex flex-col gap-2">
            @foreach ($requests as $r)
                <x-park.vehicle-row :vehicle="$r->vehicle" :href="'/zayavki/'.$r->id">
                    <span class="chip {{ $r->isOverdue() ? 'bg-danger-soft text-danger' : ($r->isOpen() ? 'bg-accent-soft text-accent-text' : 'bg-closed-soft text-closed') }}">{{ $r->type->label() }}{{ $r->type === RequestType::Move && $r->yard ? ' → '.$r->yard->name : '' }}</span>
                    @if ($r->planned_at)<span class="chip tabular-nums {{ $r->isOverdue() ? 'text-danger' : '' }}">{{ $r->planned_at->translatedFormat('j M, H:i') }}</span>@endif
                    @if (!$r->isOpen())<span class="chip">{{ $r->state->label() }}</span>@endif
                    @if ($r->contact)<span class="text-sm text-ink-muted">{{ $r->contact }}</span>@endif
                </x-park.vehicle-row>
            @endforeach
        </div>
        <div class="mt-4">{{ $requests->links() }}</div>
    @endif
</x-ui.shell>
