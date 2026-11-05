{{-- Живой документ месяца (`Closing::draft`): счёт и акт по текущим данным, ничего не записано. «Отправить» — окно письма с
     файлами, номер встаёт в момент отправки (`MailController::send` → `Closing::fix`); «Отправили сами» — фиксирует без
     письма. У исправленного (`replaces`) — тот же номер и следующая редакция, прежний аннулируется. --}}
@php
    use App\Billing\Acts\RegistryAct;
    use App\Billing\ChargeKind;
    use App\Support\Money;
    $i = $invoice; $me = auth()->user(); $party = $i->party;
    $ready = $party?->filled();
    $tow = $i->kind === ChargeKind::Tow;
    $acts = $i->isRegistry() ? array_values(RegistryAct::form($i)->files($tow)) : ['Акт хранения'];
    $byCar = $i->charges->groupBy('vehicle_id');
    $mailUrl = '/mail/new?'.http_build_query(array_filter(['closing' => $month->format('Y-m').'|'.$group, 'replaces' => $replaces?->id]));
@endphp
<x-ui.shell :title="($tow ? 'Эвакуация' : 'Хранение').' за '.mb_strtolower($month->translatedFormat('F'))" :back="['Счета и акты', '/money/closing?month='.$month->format('Y-m')]" cache="no-cache">
    <div class="-mt-3 mb-6 detail-line text-sm text-ink-muted">
        <span class="text-ink">{{ $party?->name }}</span>
        <span class="text-urgent">{{ $replaces ? 'исправленный, не отправлен' : 'не отправлен' }}</span>
        <span class="nums">номер при отправке {{ $i->label() }}</span>
        <span>{{ $i->vatRate() ? 'НДС '.$i->vatRate().' %' : 'без НДС' }}</span>
    </div>
    @if ($replaces)
        <p class="mb-4 text-sm">Отправлен {{ $replaces->label() }} на <span class="nums">{{ Money::rub($replaces->total) }}</span>, сейчас <span class="nums">{{ Money::rub($i->total) }}</span>. При отправке прежний аннулируется</p>
    @endif
    @if ($errors->has('closing'))<p class="field-error mb-4">{{ $errors->first('closing') }}</p>@endif
    @if ($skipped)<p class="field-error mb-4">Не вошли: {{ implode('; ', $skipped) }}</p>@endif
    <div class="mb-6 flex flex-wrap gap-2">
        <x-ui.doc :doc="['url' => '/money/draft/pdf?'.$query, 'type' => 'pdf', 'name' => 'Счет '.$i->fileNo().'.pdf', 'label' => 'Счёт']" class="chip"/>
        @foreach ($acts as $n => $title)
            @php $ext = $i->isRegistry() ? pathinfo(array_keys(RegistryAct::form($i)->files($tow))[$n], PATHINFO_EXTENSION) : 'pdf'; @endphp
            <x-ui.doc :doc="['url' => '/money/draft/act?'.$query.($n ? '&n='.$n : ''), 'type' => match ($ext) { 'xlsx' => 'sheet', 'docx' => 'word', default => 'pdf' }, 'name' => $title.' '.$i->fileNo().'.'.$ext, 'label' => $title]" class="chip"/>
        @endforeach
    </div>
    <section>
        <h2 class="list-head">{{ $byCar->count() }}&nbsp;ТС <span class="nums">{{ Money::rub($i->total) }}</span></h2>
        <div class="list">
            @foreach ($byCar as $rows)
                @php $v = $rows->first()->vehicle; $storage = $rows->where('kind', ChargeKind::Storage); @endphp
                <a href="/cars/{{ $v->id }}" class="row">
                    <div class="min-w-0 flex-1">
                        <div class="truncate">{{ $v->titleWithYear() }}</div>
                        <div class="row-sub">
                            @if ($v->ref)<span>{{ $v->ref }}</span>@endif
                            @if ($storage->isNotEmpty())<span class="nums">{{ $storage->min('period_from')->format('d.m') }} – {{ $storage->max('period_to')->format('d.m') }}, {{ (int) $storage->sum('qty') }} сут</span>@endif
                            @foreach ($rows->where('kind', '!=', ChargeKind::Storage) as $c)<span>{{ $c->title }}</span>@endforeach
                        </div>
                    </div>
                    <span class="nums shrink-0">{{ Money::rub($rows->sum('amount')) }}</span>
                </a>
            @endforeach
        </div>
    </section>
    @if ($me->canManagePark() && $ready)
        <x-ui.action-bar>
            <button type="button" class="btn btn-accent min-w-0 flex-1" data-controller="emit" data-action="emit#send" data-emit-event-param="letters:open" data-emit-url-param="{{ $mailUrl }}"><x-ui.icon name="send" class="size-4"/> Отправить</button>
            <form method="post" action="/money/draft/fix" class="contents" data-turbo-confirm="Документ ушёл не письмом отсюда — отметить отправленным?">
                @csrf
                <input type="hidden" name="month" value="{{ $month->format('Y-m') }}"><input type="hidden" name="group" value="{{ $group }}">
                @if ($replaces)<input type="hidden" name="replaces" value="{{ $replaces->id }}">@endif
                <button class="btn btn-quiet">Отправили сами</button>
            </form>
        </x-ui.action-bar>
        <x-mail.window/>
    @elseif (! $ready)
        <p class="mt-6 text-sm text-danger">Нет реквизитов плательщика — заполните их в списке «Счета и акты»</p>
    @endif
</x-ui.shell>
