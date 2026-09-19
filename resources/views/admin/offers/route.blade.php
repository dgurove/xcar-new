@php use App\Workflow\{Track, Actor}; @endphp
<x-ui.card title="Маршрут" class="order-1" data-controller="sheet">
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
                    <div class="flex flex-col">
                        @foreach ($r->getMedia('files') as $f)<x-ui.file :name="$f->file_name" :mime="$f->mime_type" :size="$f->humanReadableSize" href="/files/{{ $f->id }}" class="py-1"/>@endforeach
                    </div>
                @endforeach
                @if ($stage->template_id)
                    <a href="/work/mail/new?offer={{ $offer->number }}&shablon={{ $stage->template_id }}" class="btn btn-quiet btn-s self-start"><x-ui.icon name="send" class="size-4"/> Письмо вендору</a>
                @endif
                @if ($staffExits->isNotEmpty())
                    <div class="flex flex-wrap gap-2">
                        @foreach ($staffExits as $exit)
                            @if ($exit->to?->staff_fields)
                                <div data-controller="sheet">
                                    <x-ui.button type="button" size="sm" :variant="$loop->first ? 'primary' : 'secondary'" data-action="sheet#open">{{ $exit->label }}</x-ui.button>
                                    <x-ui.sheet id="exit-{{ $exit->id }}" :title="$exit->label">
                                        <form method="post" action="/offers/{{ $offer->number }}/exit/{{ $exit->id }}" class="flex flex-col gap-4">
                                            @csrf
                                            @foreach ($exit->to->staff_fields as $field)
                                                <x-route.field :field="$field" :name="'fields['.$field['key'].']'"/>
                                            @endforeach
                                            <x-ui.button block>{{ $exit->label }}</x-ui.button>
                                        </form>
                                    </x-ui.sheet>
                                </div>
                            @else
                                <form method="post" action="/offers/{{ $offer->number }}/exit/{{ $exit->id }}" @if ($exit->confirm) data-turbo-confirm="{{ $exit->confirm }}" @endif>
                                    @csrf<x-ui.button size="sm" :variant="$loop->first ? 'primary' : 'secondary'">{{ $exit->label }}</x-ui.button>
                                </form>
                            @endif
                        @endforeach
                    </div>
                @endif
            </div>
        @endforeach
        @if ($errors->has('exit'))<p class="field-error">{{ $errors->first('exit') }}</p>@endif
        @if ($offer->deal && $offer->deal->isActive())
            @php $dealInvoices = \App\Billing\Invoice::where('deal_id', $offer->deal->id)->where('state', '!=', \App\Billing\InvoiceState::Void)->with('party')->get(); @endphp
            <div class="flex flex-wrap items-center gap-1.5">
                @foreach ($dealInvoices as $i)
                    <a href="{{ \App\Support\Surface::Park->url('/money/invoices/'.$i->id) }}" class="chip nums" data-turbo="false">{{ $i->label() }} {{ \App\Support\Money::rub($i->remaining() > 0 ? $i->remaining() : $i->total) }}</a><x-billing.light :invoice="$i"/>
                @endforeach
                <a href="/work/invoices/new?offer={{ $offer->number }}" class="chip">{{ $dealInvoices->isEmpty() ? 'Выставить счёт' : 'Ещё счёт' }}</a>
            </div>
        @endif

        @php $service = $offer->vendor?->workflow(Track::Service); $pickup = $offer->position(Track::Service); $pv = $offer->parkVehicle; @endphp
        @if ($pv)
            {{-- ТС на стоянке: где стоит, сколько, открытая заявка — на хост стоянки. --}}
            <div class="flex flex-wrap items-center gap-1.5">
                <a href="{{ \App\Support\Surface::Park->url('/cars/'.$pv->id) }}" class="contents" data-turbo="false"><x-park.state :vehicle="$pv"/></a>
                @if ($tow = $pv->openRequest(\App\Park\RequestType::Tow))<a href="{{ \App\Support\Surface::Park->url('/requests/'.$tow->id) }}" class="chip" data-turbo="false">Эвакуация: {{ mb_strtolower($tow->state->label()) }}{{ $tow->planned_at ? ', '.$tow->planned_at->translatedFormat('j M') : '' }}</a>@endif
                @if ($pv->docsPending())<span class="chip">бумаги вендору не отправлены</span>@endif
            </div>
        @endif
        <div class="flex flex-wrap gap-2">
            <x-ui.button type="button" variant="ghost" size="sm" data-action="sheet#open">Поставить на этап</x-ui.button>
            @if ($service?->is_active && !$pickup && !in_array($offer->state, [\App\Offers\OfferState::Delivered, \App\Offers\OfferState::Cancelled, \App\Offers\OfferState::Archived], true))
                <form method="post" action="/offers/{{ $offer->number }}/pickup" data-turbo-confirm="Запустить вывоз автомобиля от страхователя?">@csrf<x-ui.button variant="ghost" size="sm">Нужен вывоз</x-ui.button></form>
            @elseif ($pickup && !$service?->auto_start && $pickup->stage->is($service->startStage()))
                <form method="post" action="/offers/{{ $offer->number }}/pickup" data-turbo-confirm="Отменить вывоз?">@csrf @method('delete')<x-ui.button variant="ghost" size="sm" class="text-danger">Вывоз не нужен</x-ui.button></form>
            @endif
            @if ($offer->deal)
                <a href="#deal-note" class="btn btn-ghost btn-s">Заметка к сделке</a>
            @endif
        </div>
        <x-ui.sheet id="place-on-stage" title="Поставить на этап">
            <form method="post" action="/offers/{{ $offer->number }}/stage" class="flex flex-col gap-4">
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

@if ($offer->deal && ($dealCard ?? true))
<x-ui.card title="Сделка" id="deal-note" class="order-1">
    <div class="mb-3 flex flex-wrap items-center gap-1.5">
        @if ($offer->deal->buyer)<x-ui.person :user="$offer->deal->buyer" full/><a href="tel:+{{ $offer->deal->buyer->phone }}" class="tag nums">{{ $offer->deal->buyer->phoneFormatted() }}</a>@endif
        <span class="tag nums font-semibold">{{ \App\Support\Money::rub($offer->deal->amount) }}</span>
    </div>
    <form method="post" action="/work/deals/{{ $offer->deal->id }}/note" class="flex flex-col gap-2">
        @csrf
        <textarea name="notes" class="field-input" placeholder="Заметка">{{ old('notes', $offer->deal->notes) }}</textarea>
        <x-ui.button size="sm" variant="secondary" class="self-end">Сохранить</x-ui.button>
    </form>
</x-ui.card>
@endif
