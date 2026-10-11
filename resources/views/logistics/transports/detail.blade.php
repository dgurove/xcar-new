{{-- Карточка перевозки (Logistics XCar): кадры машины, название, ход перевозки и маршрут фразой; факты строками — откуда,
     куда, принимает, кто везёт, стоимость, сроки; кадры погрузки и выгрузки. Главное действие подвалом во всю ширину:
     исполнителю «Забрал» → «Сдал» с фото, логисту — «Назначить»; правка — пилюлей, отмена — красной строкой внизу. --}}
@php
    use App\Logistics\Executor;
    use App\Logistics\TransportState;
    use App\Support\Money;
    use App\Support\Surface;
    $car = $t->car();
    $all = $me->seesAllTransports();
    $acts = $t->isExecutedBy($me) || $all;
    $editable = $all && in_array($t->state, [TransportState::New, TransportState::Assigned], true);
    $sheet = 'assign-transport-'.$t->id;
    $carLink = match (true) {
        $t->offer && $me->isStaff() => Surface::Crm->url('/offers/'.$t->offer->number),
        $t->vehicle && Surface::Park->opensFor($me) => Surface::Park->url('/cars/'.$t->vehicle->id),
        default => null,
    };
    $facts = array_filter([
        'Откуда' => $t->from_address,
        'Куда' => $t->toYard ? $t->toYard->name.($t->to_address ? ', '.$t->to_address : '') : $t->to_address,
        'Принимает' => $t->receiver,
        'Кто везёт' => $t->executorName() ? $t->executorName().($all && $t->executorUser?->phone ? ', '.\App\Support\Phone::format($t->executorUser->phone) : '') : null,
        // Стоимость — логисту и админу, и партнёру: это его деньги за эвакуацию.
        'Стоимость' => $t->cost !== null && ($all || $t->isExecutedBy($me)) ? Money::rub($t->cost) : null,
        'Отправка' => $t->ship_on?->translatedFormat('j F'),
        'Прибытие' => $t->arrive_on?->translatedFormat('j F'),
    ]);
@endphp
<x-ui.detail>
    <x-ui.row-card :title="$t->title()" :photos="$car?->visiblePhotos() ?? collect()" :action="false" :blank="false">
        <x-slot:row><x-logistics.row :t="$t"/></x-slot:row>
        {{-- Состояние — словом у названия; фразой — маршрут (регионы), без них — «внутри региона» / «между регионами». --}}
        <x-slot:badge><span class="title-state {{ $t->state === TransportState::Assigned ? 'text-ink-muted' : $t->state->tone() }}">{{ mb_strtolower($t->state->label()) }}</span></x-slot:badge>
        <x-slot:line>
            <span>{{ $t->regions() ?: mb_strtolower($t->scope->label()) }}</span>
        </x-slot:line>
        <x-slot:actions>
            @if ($editable && $t->executor)<button type="button" class="pill" data-controller="emit" data-action="emit#send" data-emit-event-param="{{ $sheet }}:open">Изменить</button>@endif
            @if ($carLink)<a href="{{ $carLink }}" class="pill" data-turbo="false">{{ $t->offer ? 'Предложение в CRM' : 'Дело на парковке' }}</a>@endif
        </x-slot:actions>
        @error('transport')<x-ui.flash tone="danger" class="mt-3">{{ $message }}</x-ui.flash>@enderror

        @if ($facts)
            <div class="list mt-4">
                @foreach ($facts as $label => $value)
                    <div class="row items-start justify-between gap-4">
                        <span class="shrink-0 text-ink-muted">{{ $label }}</span>
                        <span class="min-w-0 whitespace-pre-line break-words text-right">{!! \App\Support\Linkify::html($value) !!}</span>
                    </div>
                @endforeach
            </div>
        @endif

        @foreach (['pickup' => 'Погрузка', 'delivery' => 'Выгрузка'] as $at => $label)
            @if (($shots = $t->photosAt($at))->isNotEmpty())
                <h3 class="list-head">{{ $label }}</h3>
                <x-ui.photos :photos="$shots" id="transport-{{ $t->id }}-{{ $at }}" readonly :hide="false" :main="false" grid/>
            @endif
        @endforeach
        {{-- Опасное — красной строкой внизу, как «Отменить ссылку» в «Оплатах», а не пилюлей рядом с «Изменить». --}}
        @if ($all && $t->isOpen())
            <form method="post" action="/transports/{{ $t->id }}/cancel" class="list mt-4" data-turbo-confirm="Отменить перевозку?" data-turbo-confirm-label="Отменить перевозку">
                @csrf<button type="submit" class="row row-danger">Отменить перевозку</button>
            </form>
        @endif

        @php
            $step = $acts && in_array($t->state, [TransportState::Assigned, TransportState::Moving], true) && ($t->executor !== Executor::Carrier || $all)
                ? ($t->state === TransportState::Assigned ? 'pickup' : 'deliver') : null;
            // Забирать рано (Альфа до оплаты, документы не готовы) — кнопка называет, чего ждём, и не нажимается.
            $blocked = $step ? $t->pickupBlocker($me) : null;
        @endphp
        @if ($blocked)
            <x-slot:foot>
                <div class="detail-action"><button type="button" class="btn btn-quiet w-full" disabled>{{ $blocked }}</button></div>
            </x-slot:foot>
        @elseif ($step)
            <x-slot:foot>
                <div class="detail-action">
                    <form method="post" action="/transports/{{ $t->id }}/{{ $step }}" enctype="multipart/form-data" class="flex flex-col gap-3"
                        data-turbo-confirm="{{ $step === 'pickup' ? 'Забрали машину?' : 'Сдали машину?' }}" data-turbo-confirm-label="{{ $step === 'pickup' ? 'Забрал' : 'Сдал' }}">
                        @csrf
                        <x-ui.file-field name="photos[]" accept="image/*,.heic,.heif" multiple/>
                        <button type="submit" class="btn btn-accent w-full">{{ $step === 'pickup' ? 'Забрал' : 'Сдал' }}</button>
                    </form>
                </div>
            </x-slot:foot>
        @elseif ($editable && ! $t->executor)
            <x-slot:foot>
                <div class="detail-action"><button type="button" class="btn btn-accent w-full" data-controller="emit" data-action="emit#send" data-emit-event-param="{{ $sheet }}:open">Назначить</button></div>
            </x-slot:foot>
        @endif
    </x-ui.row-card>
    @if ($editable)
        <div data-controller="sheet" data-action="{{ $sheet }}:open@window->sheet#open" class="contents">
            <x-ui.sheet id="{{ $sheet }}" title="Перевозка" :open="$errors->hasAny(['scope', 'from_region_id', 'to_region_id', 'executor', 'executor_id', 'carrier', 'cost', 'arrive_on'])">
                <form method="post" action="/transports/{{ $t->id }}" class="flex flex-col gap-4">
                    @csrf @method('put')
                    <x-logistics.fields :transport="$t" :car="$car" prefix="assign-{{ $t->id }}" logist/>
                    <x-ui.button type="submit" variant="primary" block>Сохранить</x-ui.button>
                </form>
            </x-ui.sheet>
        </div>
    @endif
</x-ui.detail>
