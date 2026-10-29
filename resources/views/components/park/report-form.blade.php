{{-- Отчёт о приёме вендору (12.10.2026, владелец: «висит задача отправить акты приёма — а где это делать»): шаг «Письмо
     вендору» окна действия (`park/act/report`) и «Дослать фото и документы» на письмо вендора (`parent`). Кому (ответ в
     ветке заявки или на это письмо), фото приёма плиткой — все отмечены, нажатие снимает, «Добавить» — новые снимки;
     документы — слоты из настроек вендора (`IntakeReport::kinds`) и «Другой документ»: лежащий у ТС скан отмечен, «Снять» —
     камера (несколько страниц → один PDF при отправке), «Файл» — загрузка. С 29.10.2026 (Никита: «работает через раз»)
     каждый файл уходит на сервер сразу при выборе, ужатым в телефоне (`report_controller`), с ходом и повтором тапом;
     «Отправить» ждёт, пока всё загрузится, и несёт только номера — письма собирает очередь (`Park\Report`). Отчёт в пути —
     «Отправляется» с тем, что уходит, не ушёл — причина и «Повторить». Подвалом листа — кнопка, что считает, что уйдёт, и тихое «Уже
     отправили с другой почты». advance — из «Заявок». --}}
@props(['vehicle', 'advance' => false, 'parent' => null])
@php
    use App\Media\MediaUrl;
    use App\Park\IntakeReport;
    use App\Park\Report;
    use App\Park\ReportDoc;
    $report = IntakeReport::for($vehicle, $parent);
    $id = 'report-'.$vehicle->id;
    $flight = Report::inFlight($vehicle);
    $failed = $flight ? null : Report::lastFailed($vehicle);
    $listed = collect($report?->slots ?? [])->except(ReportDoc::Other->value)->flatten()->isNotEmpty();
@endphp
@if ($report && $flight)
    <section {{ $attributes->merge(['class' => 'report']) }}>
        @php $docs = $flight->docCount(); @endphp
        <x-ui.flash>Отчёт отправляется: {{ implode(' и ', array_filter([$flight->photoCount() ? $flight->photoCount().' фото' : null, $docs ? $docs.' '.\App\Support\Plural::of($docs, ['документ', 'документа', 'документов']) : null])) }}</x-ui.flash>
    </section>
@elseif ($report)
    <section {{ $attributes->merge(['class' => 'report act-fill']) }}>
        @if ($failed)
            <div class="mb-3 flex flex-col gap-2 rounded-(--radius-l) bg-danger/10 p-3 text-danger">
                <span class="text-sm">Прошлый отчёт не ушёл: {{ $failed->error ?: 'причина неизвестна' }}</span>
                <button type="submit" form="report-retry-{{ $vehicle->id }}" class="btn btn-s btn-accent self-start">Повторить</button>
            </div>
        @endif
        <form method="post" action="/cars/{{ $vehicle->id }}/report" id="{{ $id }}" class="act-fill gap-4"
              data-controller="report" data-report-url-value="/cars/{{ $vehicle->id }}/report/file"
              data-report-docs-value="{{ IntakeReport::text(true) }}" data-report-plain-value="{{ IntakeReport::text(false) }}">
            @csrf
            @if ($advance)<input type="hidden" name="advance" value="1">@endif
            @if ($report->parent && $parent)<input type="hidden" name="parent" value="{{ $report->parent->id }}">@endif
            @error('report')<x-ui.flash tone="danger">{{ $message }}</x-ui.flash>@enderror
            <div class="fields">
                <x-ui.field name="to" :id="$id.'-to'" label="Кому" :value="old('to', $report->to)" required/>
            </div>

            <div>
                <div class="report-head"><span>Фото при приёме</span><span class="nums text-ink-dim" data-report-target="photoCount"></span></div>
                <div class="report-shots" data-report-target="shots">
                    @foreach ($report->photos as $m)
                        <label class="report-shot">
                            <input type="checkbox" name="photos[]" value="{{ $m->id }}" checked data-action="report#count" data-report-target="photo">
                            <img src="{{ MediaUrl::for($m, 'w320') }}" alt="" loading="lazy">
                            <span class="report-tick"><x-ui.icon name="check" class="size-3.5"/></span>
                        </label>
                    @endforeach
                    <label class="report-shot report-add">
                        <input type="file" accept="image/*,.heic,.heif" multiple class="sr-only" data-action="change->report#photos">
                        <x-ui.icon name="camera" class="size-6"/><span class="text-sm">Добавить</span>
                    </label>
                </div>
            </div>

            <div>
                <div class="report-head"><span>Документы</span></div>
                <div class="list">
                    @foreach ($report->slots as $kind => $docs)
                        @php $k = ReportDoc::from($kind); @endphp
                        <div class="row flex-wrap items-center gap-x-2 gap-y-2" data-report-target="slot" data-kind="{{ $kind }}">
                            <span class="min-w-0 flex-1 {{ $k === ReportDoc::Other ? 'text-ink-muted' : '' }}">{{ $k->label() }}</span>
                            <label class="btn btn-s btn-quiet shrink-0 rounded-full" aria-label="Снять"><x-ui.icon name="camera" class="size-4"/><span class="hidden sm:inline">Снять</span>
                                <input type="file" accept="image/*" capture="environment" multiple class="sr-only" data-action="change->report#scan">
                            </label>
                            <label class="btn btn-s btn-quiet shrink-0 rounded-full" aria-label="Файл"><x-ui.icon name="clip" class="size-4"/><span class="hidden sm:inline">Файл</span>
                                <input type="file" accept="{{ $k === ReportDoc::Other ? '' : 'image/*,.pdf,application/pdf' }}" multiple class="sr-only" data-action="change->report#files">
                            </label>
                            <div class="flex w-full flex-wrap gap-2 empty:hidden" data-chips>
                                @foreach ($docs as $doc)
                                    {{-- Снимок «Снять», что ещё не ушёл (PDF из него собирает отправка), — «снимок N» со своим выбором; файл — без хвоста-номера. --}}
                                    @php $scan = $doc->getCustomProperty('scan'); @endphp
                                    <label class="choice report-chip" @if ($scan) data-scan="{{ $scan }}" @endif><input type="checkbox" name="docs[{{ $kind }}][]" value="{{ $doc->id }}" @checked($k !== ReportDoc::Other) data-action="report#count" data-report-target="doc"><span class="max-w-[10rem] truncate">{{ $scan ? 'снимок '.((int) $doc->getCustomProperty('page') + 1) : preg_replace('/-[a-z0-9]{13}(\.[^.]+)$/i', '$1', $doc->file_name) }}</span></label>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="fields">
                <x-ui.field name="text" :id="$id.'-text'" label="Текст" type="textarea" rows="2" :value="old('text', IntakeReport::text($listed))" data-report-target="text" data-action="input->report#edited"/>
            </div>

            <div class="sheet-foot flex flex-col items-center gap-1">
                <button type="submit" class="btn btn-accent w-full" data-report-target="submit">Отправить отчёт</button>
                @if (! $parent && auth()->user()?->parkHandles())
                    <button type="submit" form="report-sent-{{ $vehicle->id }}" class="act-quiet">Уже отправили с другой почты</button>
                @endif
            </div>
        </form>
    </section>
@else
    {{-- Ящика парковки для отправки нет — отчёт ушёл другим путём: только отметка. --}}
    <section {{ $attributes->merge(['class' => 'report']) }}>
        <p class="text-sm text-ink-muted">Ящик парковки для отправки не подключён</p>
        @if (auth()->user()?->parkHandles())
            <div class="sheet-foot mt-4"><button type="submit" form="report-sent-{{ $vehicle->id }}" class="btn btn-accent w-full">Уже отправили с другой почты</button></div>
        @endif
    </section>
@endif
{{-- Вне формы отчёта (формы не вкладываются), кнопка — в её подвале; отметку ставит и приёмщик (27.10.2026). --}}
@if (auth()->user()?->parkHandles())
    <form method="post" action="/cars/{{ $vehicle->id }}/report-sent" id="report-sent-{{ $vehicle->id }}" hidden data-turbo-confirm="Отчёт уже ушёл с другой почты?" data-turbo-confirm-label="Уже отправили">
        @csrf @if ($advance)<input type="hidden" name="advance" value="1">@endif
    </form>
@endif
@if (! empty($failed))
    <form method="post" action="/cars/{{ $vehicle->id }}/report/{{ $failed->id }}/retry" id="report-retry-{{ $vehicle->id }}" hidden>
        @csrf @if ($advance)<input type="hidden" name="advance" value="1">@endif
    </form>
@endif
