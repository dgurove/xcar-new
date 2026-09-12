@php use App\Workflow\{Track, Actor}; @endphp
<x-ui.card title="Маршрут" data-controller="sheet">
    <div class="flex flex-col gap-4">
        @foreach ($offer->positions as $position)
            @php $stage = $position->stage; $staffExits = $stage->exitsFor(Actor::Staff); @endphp
            <div class="flex flex-col gap-2">
                @if ($offer->positions->count() > 1)<div class="text-sm text-ink-muted">{{ $position->track->label() }}</div>@endif
                <x-route.status :position="$position"/>
                @if ($position->payload)
                    <div class="text-sm">@foreach ($position->payload as $k => $v)<div><span class="text-ink-muted">{{ collect($stage->staff_fields)->firstWhere('key', $k)['label'] ?? $k }}:</span> {{ $v }}</div>@endforeach</div>
                @endif
                @if ($stage->awaitsManager() && ($req = $offer->requirements()->where('stage_id', $stage->id)->whereNull('done_at')->first()))
                    <div class="text-sm text-ink-muted">Менеджеру: «{{ $req->title }}»</div>
                @endif
                @php $answered = $offer->requirements()->whereNotNull('done_at')->with('media')->get()->filter(fn ($r) => $r->media->isNotEmpty()); @endphp
                @foreach ($answered as $r)
                    <div class="flex flex-wrap gap-1.5 text-sm">
                        @foreach ($r->getMedia('files') as $f)<a href="/sdelki/fayly/{{ $f->id }}" target="_blank" class="chip"><x-ui.icon name="file" class="size-3.5"/> {{ \Illuminate\Support\Str::limit($f->file_name, 28) }}</a>@endforeach
                    </div>
                @endforeach
                @if ($stage->template_id)
                    <a href="/perepiski/pochta/novoe?offer={{ $offer->number }}&shablon={{ $stage->template_id }}" class="btn btn-quiet btn-s self-start"><x-ui.icon name="send" class="size-4"/> Письмо страховой</a>
                @endif
                @if ($staffExits->isNotEmpty())
                    <div class="flex flex-wrap gap-2">
                        @foreach ($staffExits as $exit)
                            @if ($exit->to?->staff_fields)
                                <div data-controller="sheet">
                                    <x-ui.button type="button" size="sm" :variant="$loop->first ? 'primary' : 'secondary'" data-action="sheet#open">{{ $exit->label }}</x-ui.button>
                                    <x-ui.sheet id="exit-{{ $exit->id }}" :title="$exit->label">
                                        <form method="post" action="/predlozheniya/{{ $offer->number }}/iskhod/{{ $exit->id }}" class="flex flex-col gap-4">
                                            @csrf
                                            @foreach ($exit->to->staff_fields as $field)
                                                <x-route.field :field="$field" :name="'fields['.$field['key'].']'"/>
                                            @endforeach
                                            <x-ui.button block>{{ $exit->label }}</x-ui.button>
                                        </form>
                                    </x-ui.sheet>
                                </div>
                            @else
                                <form method="post" action="/predlozheniya/{{ $offer->number }}/iskhod/{{ $exit->id }}" @if ($exit->confirm) data-turbo-confirm="{{ $exit->confirm }}" @endif>
                                    @csrf<x-ui.button size="sm" :variant="$loop->first ? 'primary' : 'secondary'">{{ $exit->label }}</x-ui.button>
                                </form>
                            @endif
                        @endforeach
                    </div>
                @endif
            </div>
        @endforeach
        @if ($errors->has('exit'))<p class="field-error">{{ $errors->first('exit') }}</p>@endif

        <div class="flex flex-wrap gap-2">
            <x-ui.button type="button" variant="ghost" size="sm" data-action="sheet#open">Поставить на этап</x-ui.button>
            @if ($offer->deal)
                <a href="#deal-note" class="btn btn-ghost btn-s">Заметка к сделке</a>
            @endif
        </div>
        <x-ui.sheet id="place-on-stage" title="Поставить на этап">
            <form method="post" action="/predlozheniya/{{ $offer->number }}/etap" class="flex flex-col gap-4">
                @csrf
                <select name="stage_id" class="field-input">
                    @foreach ($stages as $track => $list)
                        <optgroup label="{{ $track }}">@foreach ($list as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach</optgroup>
                    @endforeach
                </select>
                <x-ui.button block>Поставить</x-ui.button>
            </form>
        </x-ui.sheet>
    </div>
</x-ui.card>

@if ($offer->deal)
<x-ui.card title="Сделка" id="deal-note">
    <div class="mb-3 text-sm text-ink-muted">{{ $offer->deal->buyer?->name }} · <a href="tel:+{{ $offer->deal->buyer?->phone }}" class="text-accent-text">{{ $offer->deal->buyer?->phoneFormatted() }}</a> · <span class="font-semibold text-ink tabular-nums">{{ number_format($offer->deal->amount, 0, '', ' ') }} ₽</span></div>
    <form method="post" action="/sdelki/{{ $offer->deal->id }}/zametka" class="flex flex-col gap-2">
        @csrf
        <textarea name="notes" class="field-input" placeholder="Заметка">{{ old('notes', $offer->deal->notes) }}</textarea>
        <x-ui.button size="sm" variant="secondary" class="self-end">Сохранить</x-ui.button>
    </form>
</x-ui.card>
@endif
