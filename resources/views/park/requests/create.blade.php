{{-- Разбор письма (`?candidate=`): слева письма цепочки лентой и скан заявки страховой, справа поля дела — читаешь
     и тут же переписываешь со скана, не уходя со страницы. Заголовок — сама ТС, под ним теги, как в почте;
     своими словами письмо не пересказывается. Кнопка называет исход: заявка на приём, стоящей, и выдать,
     привязать письма к уже заведённой ТС.
     Без кандидата это прежняя ручная «Новая заявка» одной колонкой; тип «приём» / «эвакуация» решает «Доставка». --}}
@php
    use App\Mail\CandidateStage;
    use App\Mail\Extraction\Intent;
    use App\Park\{RequestType, Delivery};
    $p = $p ?? [];
    $val = fn ($k) => old($k, $p[$k] ?? null);
    $letters = $candidate !== null;
    $delivery = $letters ? $val('delivery') : old('delivery', Delivery::Self->value);
    $stage = $candidate?->stage ?? CandidateStage::Intake;
    $v = fn (string $f) => $candidate?->value($f);
    // Заявка на приём нужна, только пока машины нет на парковке; иначе разбор письма — это привязка писем к делу.
    $makesRequest = ! $letters || ! $vehicle || ($stage === CandidateStage::Intake && RequestType::Intake->allowedFor($vehicle->state));
    $heading = $letters ? $candidate->title().($candidate->hasCar() && $v('year') ? ', '.$v('year') : '') : 'Новая заявка';
    // Письмо вендора без нашего ответа после него — от нас ждут слов, а не только заведения.
    // То же правило, что в почте и в деле: ветка с `needs_reply_at` ждёт от нас слов, а не только заведения.
    $waits = $letters && \App\Mail\Thread::whereIn('id', $messages->pluck('thread_id')->filter()->unique())->whereNotNull('needs_reply_at')->exists();
    $act = match (true) {
        ! $letters => 'Завести',
        $vehicle && $makesRequest => 'Добавить заявку на приём',
        $vehicle && $stage === CandidateStage::Sold => 'Привязать письма и выдать',
        $vehicle => 'Привязать письма к ТС',
        $stage === CandidateStage::Sold => 'Завести и выдать',
        $stage !== CandidateStage::Intake => 'Завести стоящей',
        default => 'Завести заявку на приём',
    };
@endphp
<x-ui.shell :title="$letters ? 'Разбор письма' : 'Новая заявка'" :heading="$heading" :back="$letters ? ['Из писем', '/requests/from-mail'] : null">
    @if ($letters)
        <div class="-mt-3 mb-5 sm:-mt-4">
            @include('park.requests.letter-tags', ['candidate' => $candidate, 'letter' => $letter, 'waits' => $waits])
        </div>
    @endif
    @if ($errors->any())<p class="field-error mb-4">{{ $errors->first() }}</p>@endif
    <div class="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_28rem]">
        @if ($letters)
            <div class="flex min-w-0 flex-col gap-4">
                <x-ui.card title="Письма" :count="$messages->count()">
                    <x-mail.chain :messages="$messages" :candidate="$candidate" :focus="false" fold reply/>
                </x-ui.card>
                @if ($scans->isNotEmpty())
                    @include('park.requests.scan-card', ['scans' => $scans])
                @endif
            </div>
        @endif
        {{-- Правая колонка липнет, пока читаешь письмо и скан слева; кнопка заканчивает то, что заполняешь, плашки тут нет. --}}
        <div class="flex min-w-0 flex-col gap-4 {{ $letters ? 'lg:sticky lg:top-24 lg:max-h-[calc(100dvh-8rem)] lg:overflow-y-auto lg:-mr-2 lg:pr-2' : '' }}">
        <form method="post" action="/requests" id="request-form" data-controller="vin draft" class="flex min-w-0 flex-col gap-4">
            @csrf
            <input type="hidden" name="type" value="{{ $type->value }}">
            @if ($candidate)
                <input type="hidden" name="candidate_id" value="{{ $candidate->id }}">
                @foreach ((array) ($p['flags'] ?? []) as $f)<input type="hidden" name="flags[]" value="{{ $f }}">@endforeach
                @foreach ((array) ($p['docs_required'] ?? []) as $d)<input type="hidden" name="docs_required[]" value="{{ $d }}">@endforeach
            @endif
            @if ($vehicle)
                {{-- ТС уже есть: заводить нечего, письма и заявка идут к ней — вместо пустых полей сама машина. --}}
                <x-ui.card title="Транспортное средство">
                    <x-park.vehicle-row :vehicle="$vehicle" :href="'/cars/'.$vehicle->id">
                        <x-park.state :vehicle="$vehicle"/>
                    </x-park.vehicle-row>
                </x-ui.card>
                <input type="hidden" name="vehicle_id" value="{{ $vehicle->id }}">
            @elseif (in_array($type, [RequestType::Intake, RequestType::Tow], true))
                <x-ui.card title="Транспортное средство">
                    <x-park.vehicle-fields :values="$p" :brand="$p['brand'] ?? null" :model="$p['model'] ?? null" :vendors="$vendors" :categories="$categories" :cols="$letters ? 'grid-cols-1 sm:grid-cols-2' : 'grid-cols-2 sm:grid-cols-3'"/>
                </x-ui.card>
            @else
                <x-ui.card title="Транспортное средство">
                    <x-ui.combobox name="vehicle_id" label="Номер убытка, VIN или госномер" url="/reference/cars"/>
                </x-ui.card>
            @endif
            @if (! $vehicle && ($p['stages'] ?? null))
                @php $st = $p['stages']; $sv = fn ($k) => old('stages.'.$k, $st[$k] ?? null); @endphp
                {{-- По письмам ТС уже принята (и, может быть, продана): этапы менеджеру на проверку, заведётся стоящей. --}}
                <x-ui.card title="По письмам">
                    <div class="flex flex-col gap-4" data-controller="spots" data-spots-map-value="{{ json_encode($yardRows) }}">
                        <div class="flex flex-col gap-3">
                            <x-ui.check name="stages[stored]" :checked="(bool) $sv('stored')">{{ $st['stored_title'] ?? 'Принята' }}</x-ui.check>
                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <x-ui.field name="stages[accepted_at]" label="Когда" type="date" :value="$sv('accepted_at')"/>
                                {{-- Парковка стоит, только когда её знает адрес отправителя (контакт вендора); иначе пусто. --}}
                                <x-ui.field name="stages[yard_id]" label="Парковка" :options="$yards" placeholder="—" :value="$sv('yard_id')" data-spots-target="yard" data-action="change->spots#sync"/>
                                <x-ui.field name="stages[spot]" label="Место" list="spots-list" autocapitalize="characters" :value="$sv('spot')"/>
                                <datalist id="spots-list" data-spots-target="list"></datalist>
                            </div>
                        </div>
                        <div class="flex flex-col gap-3">
                            <x-ui.check name="stages[sold]" :checked="(bool) $sv('sold')">Продана, покупатель заберёт</x-ui.check>
                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <x-ui.field name="stages[sold_at]" label="Когда" type="date" :value="$sv('sold_at')"/>
                                <x-ui.field name="stages[pickup_name]" label="Кому выдать" :value="$sv('pickup_name')"/>
                                <x-ui.field name="stages[pickup_phone]" label="Телефон" type="tel" :value="$sv('pickup_phone')"/>
                            </div>
                        </div>
                    </div>
                </x-ui.card>
            @elseif ($makesRequest)
                <x-ui.card title="Заявка">
                    <div class="grid gap-3 {{ $letters ? 'grid-cols-1 sm:grid-cols-2' : 'grid-cols-2 sm:grid-cols-3' }}">
                        @if ($type === RequestType::Intake && ! $letters)
                            {{-- Эвакуатор или сам — узнаём по телефону; «ещё не знаем» оставляет заявку в «Связаться». Руками заводят, когда уже знают — по умолчанию «сам». --}}
                            <div class="field col-span-full">
                                <span class="field-label">Доставка</span>
                                <div class="flex flex-wrap gap-1.5">
                                    <label class="choice"><input type="radio" name="delivery" value="" @checked(!$delivery)><span>Ещё не знаем</span></label>
                                    @foreach (Delivery::cases() as $d)
                                        <label class="choice"><input type="radio" name="delivery" value="{{ $d->value }}" @checked($delivery === $d->value)><span>{{ $d->label() }}</span></label>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                        @if ($type === RequestType::Move)
                            <x-ui.field name="yard_id" label="Куда" :options="$yards" placeholder="—"/>
                        @endif
                        <x-ui.field name="planned_at" :label="$letters ? 'Когда привезут' : 'Когда'" type="datetime-local" :value="$val('planned_at')"/>
                        @if ($type === RequestType::Tow || $letters)
                            <x-ui.field name="from_address" label="Откуда" :value="$val('from_address') ?? $vehicle?->offer?->inspection_address" span="col-span-2"/>
                        @endif
                        @if ($vehicle || !in_array($type, [RequestType::Intake, RequestType::Tow], true))
                            <x-ui.field name="contact_name" :label="$type === RequestType::Tow ? 'Страхователь' : 'Кто сдаёт'" :value="$val('contact_name') ?? $vehicle?->contact_name"/>
                            <x-ui.field name="contact_phone" label="Телефон" type="tel" :value="$val('contact_phone') ?? $vehicle?->contact_phone"/>
                        @endif
                        <x-ui.field name="note" label="Заметка" type="textarea" span="col-span-full" :value="$val('note')"/>
                    </div>
                </x-ui.card>
            @endif
        </form>
        @if ($letters)
            <div class="flex items-center gap-2">
                <x-ui.button form="request-form" class="min-w-0 flex-1">{{ $act }}</x-ui.button>
                <form method="post" action="/requests/from-mail/{{ $candidate->id }}/decline" class="contents">@csrf<button class="btn btn-ghost shrink-0" data-turbo-confirm="Не заявка? Письмо уйдёт из «Ждут»">Не заявка</button></form>
            </div>
        @endif
        </div>
    </div>
    @unless ($letters)
        <x-ui.action-bar><x-ui.button form="request-form" class="min-w-0 flex-1">{{ $act }}</x-ui.button></x-ui.action-bar>
    @endunless
</x-ui.shell>
