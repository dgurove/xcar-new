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
    // Админ жмёт «Забрал» за менеджера, которому поручен вывоз: ход не его.
    $forManager = $user->isAdmin() && $evacuator && ! $mine;
    $blockName = fn ($block) => $block->stages->contains(fn ($s) => $s->car_place === \App\Offers\CarPlace::Keeper)
        ? ($mine ? 'Стоит у вас' : $block->name) : $block->name;
    // Куда и когда ехать: наши поля шага, а без них — страхователь и адрес осмотра из предложения.
    // Без денег поставщику и без названия вендора (15.10.2026): поля вписывают сотрудники из писем страховой.
    $fields = collect($stage?->staff_fields ?? [])->reject(fn ($f) => preg_match('/платёж|оплат|сумм|перевод|цен/iu', $f['label']))
        ->mapWithKeys(fn ($f) => [$f['label'] => \App\Support\VendorNames::strip((string) ($position->payload[$f['key']] ?? ''))])->filter();
    if ($canPick && ! $fields->has('Адрес') && $offer->inspection_address) $fields['Адрес'] = $offer->inspection_address;
    // Машина едет в гараж — её закупочная первой строкой, как на странице машины: сотруднику нынешняя, держателю гаража —
    // закреплённая при подтверждении (16.10.2026); вывозчику-менеджеру чужой машины — нет.
    $garagePrice = $user->isAdmin() ? $offer->garageCar?->price() : ($offer->garageCar?->manager_id === $user->id ? $offer->garageCar->managerPrice() : null);
    if ($canPick && ! $fields->has('Контакт') && ($offer->insured_name || $offer->insured_phone)) $fields['Контакт'] = trim($offer->insured_name.' '.$offer->insured_phone);
@endphp
<x-ui.shell :title="$offer->titleWithYear()" :back="['Гараж', '/garage']">
    {{-- Состояние — словом у названия, как на странице машины; сотруднику куда везём — строкой справа. --}}
    <x-slot:badge><x-ui.state :tone="$tone" class="title-state">{{ $word }}</x-ui.state></x-slot:badge>
    @if ($user->isAdmin())
        <x-slot:actions><span class="text-sm text-ink-muted">{{ mb_strtolower($offer->pickupDestination()->label()) }}</span></x-slot:actions>
    @endif
    @error('exit')<x-ui.flash tone="danger" class="mb-4">{{ $message }}</x-ui.flash>@enderror

    <x-offer.object :offer="$offer" :photos="$photos" :docs="$docs">
        @if ($offer->chatOpenFor($user))
            <x-slot:aside><a href="/account/chats/offer/{{ $offer->number }}" class="btn btn-quiet w-full"><x-ui.icon name="chat" class="size-5"/> Написать</a></x-slot:aside>
        @endif
        @if ($fields->isNotEmpty() || $garagePrice)
            <div class="list">
                @if ($garagePrice)<div class="row justify-between gap-4"><span class="shrink-0 text-ink-muted">Закупочная</span><span class="nums">{{ \App\Support\Money::rub($garagePrice) }}</span></div>@endif
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
                                    <span class="step-title flex-1">{{ $blockName($step['block']) }}</span>
                                    @if ($step['state'] === Path::DONE && $step['at'])<span class="nums shrink-0 text-sm text-ink-dim">{{ $step['at']->translatedFormat('j M') }}</span>@endif
                                </div>
                                @if ($step['state'] === Path::CURRENT)<p class="step-hint">{{ $canPick ? ($forManager ? 'ход менеджера' : 'ваш ход') : 'готовим мы' }}</p>@endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </x-offer.object>

    @if ($canPick)
        <x-ui.action-bar>
            <form method="post" action="/garage/pickups/{{ $offer->number }}/picked" class="contents" data-turbo-confirm="{{ $forManager ? $evacuator->shortName().' забрал ТС?' : 'Забрали ТС?' }}" data-turbo-confirm-label="Забрал">
                @csrf<button type="submit" class="btn btn-accent min-w-0 flex-1">{{ $forManager ? 'Забрал за менеджера' : 'Забрал' }}</button>
            </form>
        </x-ui.action-bar>
    @endif
</x-ui.shell>
