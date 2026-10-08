{{-- «Кому показывать» в сделке — тем же видом, что у предложения: сводка и чипы. Белый список вместо волн:
     идёт сделка, волны кончились. Пусто — машину не видит никто, кроме участников сделки. Выбранные видят её
     в предложениях с меткой «Идёт сделка», их подтверждение встаёт резервом. Новым — уведомление, как о новом.
     Живёт карточкой в дорожке «Сделка» (вне главной формы — поля шлют её через form=). --}}
@php
    use App\Offers\DealAudience;
    $dealShow = auth()->user()->canManageCrm();
    $pickedUsers = old('deal_audience_users', $offer->dealAudience->where('audience_type', DealAudience::USER)->pluck('audience_id')->map(fn ($id) => (int) $id)->all());
    $pickedGroups = old('deal_audience_groups', $offer->dealAudience->where('audience_type', DealAudience::GROUP)->pluck('audience_id')->map(fn ($id) => (int) $id)->all());
    $groups = collect($audienceOptions['groups'] ?? []);
    $managers = collect($audienceOptions['managers'] ?? []);
@endphp
@if ($dealShow)
    {{-- Блок пришёл в форме: снятые галки считаются снятыми (`OfferRequest::payload`). --}}
    <input type="hidden" name="_deal_show" value="1" form="offer-form">
    <p class="text-sm text-ink-muted">Видят: {{ DealAudience::summary($offer) }}, в сделке с {{ $offer->deal?->buyer?->shortName() ?? '—' }}</p>
    @if ($groups->isNotEmpty())
        <div class="field mt-3">
            <span class="field-label">Группы</span>
            <div class="flex flex-wrap gap-1.5">
                @foreach ($groups as $g)
                    <label class="choice"><input type="checkbox" name="deal_audience_groups[]" value="{{ $g['id'] }}" form="offer-form" @checked(in_array($g['id'], $pickedGroups))><span>{{ $g['name'] }}</span></label>
                @endforeach
            </div>
        </div>
    @endif
    @if ($managers->isNotEmpty())
        <div class="field mt-3">
            <span class="field-label">Менеджеры</span>
            <div class="flex flex-wrap gap-1.5">
                @foreach ($managers as $m)
                    <label class="choice"><input type="checkbox" name="deal_audience_users[]" value="{{ $m['id'] }}" form="offer-form" @checked(in_array($m['id'], $pickedUsers))><span>{{ $m['name'] }}</span></label>
                @endforeach
            </div>
        </div>
    @endif
@endif
