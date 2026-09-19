{{-- Окошко заявки: ТС с фото, тип и состояние, срок, контакт, исполнитель; действие — на страницу заявки (формы с фото в окошко не тащим). --}}
@php
    use App\Park\RequestState;
    $href = '/requests/'.$req->id;
    $verb = $req->verb() ?? 'Открыть';
@endphp
<turbo-frame id="peek" target="_top">
    <x-ui.peek :href="$href" :title="$vehicle->titleWithYear()" :photos="$vehicle->visiblePhotos()" :action="$verb">
        <x-slot:marks>
            <x-ui.pill :tone="$req->isOverdue() ? 'danger' : ($req->isOpen() ? 'soft' : 'closed')" class="!min-h-0 !py-1 text-xs">{{ $req->type->label() }}</x-ui.pill>
            @if ($req->state !== RequestState::New)<span class="tag">{{ $req->state->label() }}</span>@endif
            @if ($req->planned_at)<span class="tag nums {{ $req->isOverdue() ? 'text-danger' : '' }}">{{ $req->planned_at->translatedFormat('j M, H:i') }}</span>@endif
            @if ($vehicle->ref)<span class="tag nums">{{ $vehicle->ref }}</span>@endif
            @if ($vehicle->plate)<span class="tag nums">{{ $vehicle->plate }}</span>@endif
            @if ($vehicle->vendor)<span class="tag">{{ $vehicle->vendor->name }}</span>@endif
            @if ($req->yard ?? $vehicle->yard)<x-ui.place class="tag">{{ ($req->yard ?? $vehicle->yard)->name }}</x-ui.place>@endif
            @if ($req->from_address)<x-ui.place class="tag">{{ $req->from_address }}</x-ui.place>@endif
            @if ($req->contact_phone)<a href="tel:+{{ preg_replace('/\D+/', '', $req->contact_phone) }}" class="tag nums"><x-ui.icon name="phone" class="size-3.5 shrink-0"/>{{ trim($req->contact_name.' '.$req->contact_phone) }}</a>@elseif ($req->contact_name)<span class="tag">{{ $req->contact_name }}</span>@endif
            @if ($req->assignee)<x-ui.person :user="$req->assignee" class="tag"/>@endif
            @if ($req->carrier)<span class="tag">{{ $req->carrier }}</span>@endif
            @if ($req->cost)<span class="tag nums">{{ \App\Support\Money::rub($req->cost) }}</span>@endif
        </x-slot:marks>
        @if ($req->note)<p class="mt-3 text-sm text-ink-muted">{{ $req->note }}</p>@endif
        @if (! $req->isOpen() && $req->doneBy)<p class="mt-3 text-sm text-ink-dim">{{ $req->state->label() }} — {{ $req->doneBy->name }}{{ $req->done_at ? ', '.$req->done_at->translatedFormat('j M, H:i') : '' }}</p>@endif
        <x-slot:row><x-park.request-row :req="$req"/></x-slot:row>
    </x-ui.peek>
</turbo-frame>
