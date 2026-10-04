{{-- Вывоз, порученный менеджеру — полная страница ТС (`x-offer.object`): кадры, характеристики, документы, открытые
     менеджеру. Своё у вывоза: состояние словом под заголовком, путь по блокам, на «Вывозе и осмотре» — дата, адрес и
     контакт, что вписали мы; внизу «Забрал», когда его ход. Это не гараж: продавать ТС не обязательно ему. --}}
@php
    use App\Offers\PickupState;
    use App\Workflow\Path;
    $user = auth()->user();
    [$word, $tone] = PickupState::of($offer, $user);
    $stage = $position?->stage;
    $evacuator = $offer->evacuator;
    $mine = $evacuator && $evacuator->id === $user->id;
    $blockName = fn ($block) => $block->stages->contains(fn ($s) => $s->car_place === \App\Offers\CarPlace::Keeper)
        ? ($mine ? 'Стоит у вас' : $block->name) : $block->name;
    // Куда и когда ехать: наши поля шага, а без них — страхователь и адрес осмотра из предложения.
    $fields = collect($stage?->staff_fields ?? [])->mapWithKeys(fn ($f) => [$f['label'] => $position->payload[$f['key']] ?? null])->filter();
    if ($canPick && ! $fields->has('Адрес') && $offer->inspection_address) $fields['Адрес'] = $offer->inspection_address;
    if ($canPick && ! $fields->has('Контакт') && ($offer->insured_name || $offer->insured_phone)) $fields['Контакт'] = trim($offer->insured_name.' '.$offer->insured_phone);
@endphp
<x-ui.shell :title="$offer->titleWithYear()" :back="['Гараж', '/garage']">
    <div class="-mt-3 mb-5 flex flex-wrap items-center gap-x-3 gap-y-1.5 text-sm">
        <x-ui.state :tone="$tone" class="text-sm">{{ $word }}</x-ui.state>
        @if ($user->isAdmin())<span class="text-ink-muted">{{ $evacuator?->shortName() }}, {{ mb_strtolower($offer->pickupDestination()->label()) }}</span>@endif
    </div>
    @error('exit')<x-ui.flash tone="danger" class="mb-4">{{ $message }}</x-ui.flash>@enderror

    <x-offer.object :offer="$offer" :photos="$photos" :docs="$docs">
        @if ($fields->isNotEmpty())
            <div class="list">
                @foreach ($fields as $label => $value)
                    <div class="row items-start justify-between gap-4">
                        <span class="shrink-0 text-ink-muted">{{ $label }}</span>
                        <span class="min-w-0 whitespace-pre-line break-words text-right">@if ($label === 'Дата вывоза' && strtotime($value)){{ \Illuminate\Support\Carbon::parse($value)->translatedFormat('j F') }}@else{!! \App\Support\Linkify::html($value) !!}@endif</span>
                    </div>
                @endforeach
            </div>
        @endif

        @if ($path->isNotEmpty())
            <div class="box">
                <h2 class="box-title">Вывоз</h2>
                <div class="steps mt-3">
                    @foreach ($path as $step)
                        <div class="step step--{{ $step['state'] === Path::CURRENT && $canPick ? 'ask' : $step['state'] }}">
                            <span class="step-dot">@if ($step['state'] === Path::DONE)<x-ui.icon name="check" class="size-3"/>@endif</span>
                            <div class="step-body">
                                <div class="step-head">
                                    <span class="step-title min-w-0 flex-1">{{ $blockName($step['block']) }}</span>
                                    @if ($step['state'] === Path::DONE && $step['at'])<span class="nums shrink-0 text-sm text-ink-dim">{{ $step['at']->translatedFormat('j M') }}</span>@endif
                                </div>
                                @if ($step['state'] === Path::CURRENT)<p class="step-hint">{{ $canPick ? 'ваш ход' : 'готовим мы' }}</p>@endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </x-offer.object>

    @if ($canPick)
        <x-ui.action-bar>
            <form method="post" action="/garage/pickups/{{ $offer->number }}/picked" class="contents" data-turbo-confirm="Забрали ТС?" data-turbo-confirm-label="Забрал">
                @csrf<button type="submit" class="btn btn-accent min-w-0 flex-1">Забрал</button>
            </form>
        </x-ui.action-bar>
    @endif
</x-ui.shell>
