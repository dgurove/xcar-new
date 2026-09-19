{{-- Заявка в плитках и строках — карточка ТС с чипами заявки: тип пилюлей с тоном просрочки, срок, состояние, контакт, исполнитель; всё ведёт на заявку. --}}
@props(['req'])
@php
    use App\Park\RequestState; use App\Park\RequestType;
    $tone = $req->isOverdue() ? 'danger' : ($req->isOpen() ? 'soft' : 'closed');
    $verb = $req->verb() ?? 'Открыть';
@endphp
<x-park.card :vehicle="$req->vehicle" :href="'/requests/'.$req->id" :action="$verb">
    <x-ui.pill :tone="$tone" class="!min-h-0 !py-0.5 text-xs">{{ $req->type->label() }}@if ($req->type === RequestType::Move && $req->yard) <x-ui.place>{{ $req->yard->name }}</x-ui.place>@endif</x-ui.pill>
    @if ($req->planned_at)<span class="tag nums {{ $req->isOverdue() ? 'text-danger' : '' }}">{{ $req->planned_at->translatedFormat('j M, H:i') }}</span>@endif
    @if (! $req->isOpen())<span class="tag">{{ $req->state->label() }}</span>@elseif ($req->state !== RequestState::New)<span class="tag">{{ $req->state->label() }}</span>@endif
    @if ($req->contact_phone)<a href="tel:+{{ preg_replace('/\D+/', '', $req->contact_phone) }}" class="tag nums"><x-ui.icon name="phone" class="size-3.5 shrink-0"/>{{ trim($req->contact_name.' '.$req->contact_phone) }}</a>@elseif ($req->contact_name)<span class="tag">{{ $req->contact_name }}</span>@endif
    @if ($req->assignee)<x-ui.person :user="$req->assignee" class="tag"/>@endif
</x-park.card>
