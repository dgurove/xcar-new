{{-- Отчёт о приёме вендору (12.10.2026, владелец: «висит задача отправить акты приёма — а где это делать»): шаг «Письмо
     вендору» окна действия (`park/act/report`). То, что раньше уходило руками из mail.ru, — одной формой. Кому (ответ в
     ветке заявки), фото приёма плиткой — все отмечены, нажатие снимает, «Добавить» — новые снимки; документы — слоты из
     настроек вендора (`vendors.intake_report_docs`): лежащий у ТС скан отмечен, «Снять» — камера (несколько страниц → один
     PDF), «Файл» — загрузка; фраза письма — как писали («фотоотчет и скан-копии документов…»). Подвалом листа — кнопка,
     что считает, что уйдёт, и тихое «Уже отправили с другой почты» (отчёт ушёл с личной почты). advance — из «Заявок». --}}
@props(['vehicle', 'advance' => false])
@php
    use App\Media\MediaUrl;
    use App\Park\IntakeReport;
    use App\Park\ReportDoc;
    $report = IntakeReport::for($vehicle);
    $id = 'report-'.$vehicle->id;
@endphp
@if ($report)
    <section {{ $attributes->merge(['class' => 'report act-fill']) }}>
        <form method="post" action="/cars/{{ $vehicle->id }}/report" enctype="multipart/form-data" id="{{ $id }}" class="act-fill gap-4"
              data-controller="report" data-report-docs-value="{{ IntakeReport::text(true) }}" data-report-plain-value="{{ IntakeReport::text(false) }}">
            @csrf
            @if ($advance)<input type="hidden" name="advance" value="1">@endif
            @error('report')<x-ui.flash tone="danger">{{ $message }}</x-ui.flash>@enderror
            <div class="fields">
                <x-ui.field name="to" :id="$id.'-to'" label="Кому" :value="old('to', $report->to)" required/>
            </div>

            <div>
                <div class="report-head"><span>Фото при приёме</span><span class="nums text-ink-dim" data-report-target="photoCount"></span></div>
                <div class="report-shots">
                    @foreach ($report->photos as $m)
                        <label class="report-shot">
                            <input type="checkbox" name="photos[]" value="{{ $m->id }}" checked data-action="report#count" data-report-target="photo">
                            <img src="{{ MediaUrl::for($m, 'w320') }}" alt="" loading="lazy">
                            <span class="report-tick"><x-ui.icon name="check" class="size-3.5"/></span>
                        </label>
                    @endforeach
                    <label class="report-shot report-add">
                        <input type="file" name="new_photos[]" accept="image/*,.heic,.heif" multiple class="sr-only" data-action="change->report#count" data-report-target="newPhotos">
                        <x-ui.icon name="camera" class="size-6"/><span class="text-sm" data-report-target="newLabel">Добавить</span>
                    </label>
                </div>
            </div>

            @if ($report->slots)
                <div>
                    <div class="report-head"><span>Документы</span></div>
                    <div class="list">
                        @foreach ($report->slots as $kind => $docs)
                            @php $k = ReportDoc::from($kind); @endphp
                            <div class="row flex-wrap items-center gap-x-2 gap-y-2" data-report-target="slot">
                                <span class="min-w-0 flex-1">{{ $k->label() }}
                                    <span class="block text-sm text-ink-muted empty:hidden" data-report-target="slotNote"></span>
                                </span>
                                @foreach ($docs as $doc)
                                    <label class="choice"><input type="checkbox" name="docs[{{ $kind }}][]" value="{{ $doc->id }}" checked data-action="report#count" data-report-target="doc"><span class="max-w-[10rem] truncate">{{ $doc->file_name }}</span></label>
                                @endforeach
                                <label class="btn btn-s btn-quiet shrink-0 rounded-full" aria-label="Снять"><x-ui.icon name="camera" class="size-4"/><span class="hidden sm:inline">Снять</span>
                                    <input type="file" name="scans[{{ $kind }}][]" accept="image/*" capture="environment" multiple class="sr-only" data-action="change->report#count" data-report-target="scan">
                                </label>
                                <label class="btn btn-s btn-quiet shrink-0 rounded-full" aria-label="Файл"><x-ui.icon name="clip" class="size-4"/><span class="hidden sm:inline">Файл</span>
                                    <input type="file" name="files[{{ $kind }}][]" accept="image/*,.pdf,application/pdf" multiple class="sr-only" data-action="change->report#count" data-report-target="file">
                                </label>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="fields">
                <x-ui.field name="text" :id="$id.'-text'" label="Текст" type="textarea" rows="2" :value="old('text', IntakeReport::text(collect($report->slots)->flatten()->isNotEmpty()))" data-report-target="text" data-action="input->report#edited"/>
            </div>

            <div class="sheet-foot flex flex-col items-center gap-1">
                <button type="submit" class="btn btn-accent w-full" data-report-target="submit">Отправить отчёт</button>
                @if (auth()->user()?->parkHandles())
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
