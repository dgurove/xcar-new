{{-- «Из дела» под письмом о машине (02.11.2026: страховая просит «скан ПТС», «фото колпаков» — Сергей искал их у себя
     в телефоне): акты, кадры этапа (до 12) и документы машины галками; отмеченное прикладывает отправка
     (`MailController::caseFiles`). Свёрнуто, как «Исходное письмо». --}}
@php
    $stages = $vehicle->photos()->groupBy(fn ($m) => \App\Park\PhotoStage::of($m)->value);
    $papers = $vehicle->papers();
    $acts = array_filter(['intake' => $vehicle->accepted_at ? 'Акт приёма' : null, 'release' => $vehicle->released_at ? 'Акт выдачи' : null]);
    // Ошибка отправки вернула редактор — отмеченное остаётся отмеченным, а блок раскрытым.
    $picked = fn (string $key, $value) => in_array((string) $value, array_map('strval', (array) old($key, [])), true);
    $open = old('case_act') || old('case_stage') || old('case');
@endphp
@if ($acts || $stages->isNotEmpty() || $papers->isNotEmpty())
    <details class="compose-quote compose-case" name="compose-extra-{{ $frame ?? 'page' }}" @if ($open) open @endif>
        <summary class="compose-chip"><x-ui.icon name="clip" class="size-4"/><span>Из дела</span><x-ui.chevron class="compose-quote-chev"/></summary>
        <div class="mt-2 flex flex-wrap gap-2">
            @foreach ($acts as $kind => $label)
                <label class="compose-file check"><input type="checkbox" name="case_act[]" value="{{ $kind }}" @checked($picked('case_act', $kind))><span>{{ $label }}</span></label>
            @endforeach
            @foreach (\App\Park\PhotoStage::cases() as $stage)
                @if ($n = $stages->get($stage->value)?->count())
                    <label class="compose-file check"><input type="checkbox" name="case_stage[]" value="{{ $stage->value }}" @checked($picked('case_stage', $stage->value))><span>{{ $stage->label() }}</span><span class="text-ink-muted">{{ $n > 12 ? '12 из '.$n : $n }}</span></label>
                @endif
            @endforeach
            @foreach ($papers as $paper)
                <label class="compose-file check min-w-0"><input type="checkbox" name="case[]" value="{{ $paper->id }}" @checked($picked('case', $paper->id))><span class="truncate">{{ $paper->file_name }}</span><span class="shrink-0 text-ink-muted">{{ $paper->human_readable_size }}</span></label>
            @endforeach
        </div>
    </details>
@endif
