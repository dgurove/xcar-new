{{-- Поля письма редактора в окне писем (compose-frame) — письмо пишется только окном (28.10.2026). --}}
@csrf
@if ($parent)<input type="hidden" name="parent" value="{{ $parent->id }}">@endif
@if ($thread)<input type="hidden" name="thread" value="{{ $thread->id }}">@endif
@if ($offer)<input type="hidden" name="offer" value="{{ $offer->id }}">@endif
@if ($vehicle ?? null)<input type="hidden" name="vehicle" value="{{ $vehicle->id }}">@endif
@if ($back ?? null)<input type="hidden" name="back" value="{{ $back }}">@endif
@if ($invoice ?? null)<input type="hidden" name="invoice" value="{{ $invoice->id }}">@endif
<input type="hidden" name="retry" value="{{ request()->getRequestUri() }}">
@if ($then ?? null)<input type="hidden" name="then" value="{{ $then }}">@endif
{{-- Заявка поставщику: сделки её файла — отправили письмо, они идут дальше «Заявка отправлена». --}}
@if ($requestDeals ?? null)<input type="hidden" name="request_deals" value="{{ old('request_deals', $requestDeals) }}">@endif
@php
    // Имена известных адресов — тегам «Кому» и «Копии» вместо голой почты.
    $names = $parent ? collect([mb_strtolower((string) $parent->from_email) => $parent->from_name])
        ->merge($parent->addresses->mapWithKeys(fn ($a) => [mb_strtolower($a->email) => $a->name]))->filter()->all() : [];
    $ccOpen = trim((string) old('cc', $defaults['cc'] ?? '')) !== '';
    $cancel = $parent && str_starts_with((string) ($frame ?? ''), 'reply') ? ["{$base}/{$thread->id}/reply/{$parent->id}?cancel=1", $frame]
        : ($thread ? ["{$base}/{$thread->id}/window", 'letters-frame'] : null);
@endphp
{{-- Одна плашка, как в Gmail и Apple Mail (28.10.2026, владелец: «как нормальные люди бы этот редактор сделали»):
     шапка строками «От / Кому / Копия / Тема» с подписью слева, поле письма без рамки, вложения чипами, внизу «Отправить»,
     скрепка и «Aa» (панель форматирования). --}}
<div class="compose min-w-0">
    @if ($errors->any() && ! $errors->has('to') && ! $errors->has('cc'))<p class="field-error px-4 pt-3">{{ $errors->first() }}</p>@endif
    <div class="compose-row">
        <span class="compose-label">От</span>
        @if ($accounts->count() > 1)
            <select name="account" class="compose-from" aria-label="От кого">
                @foreach ($accounts as $a)
                    <option value="{{ $a->slug }}" @selected(old('account', $account->slug) === $a->slug)>{{ $a->fromName() }} &lt;{{ $a->email }}&gt;</option>
                @endforeach
            </select>
        @else
            <input type="hidden" name="account" value="{{ $account->slug }}">
            <span class="compose-value text-ink-muted">{{ $account->fromName() }} &lt;{{ $account->email }}&gt;</span>
        @endif
    </div>
    <x-mail.recipients name="to" label="Кому" :value="$defaults['to']" :url="$base.'/addresses'" :names="$names" required>
        <button type="button" class="compose-cc-toggle" @if ($ccOpen) hidden @endif
                onclick="const r = this.closest('.compose').querySelector('.compose-recipients[hidden]'); if (r) { r.hidden = false; r.querySelector('.recipients-input').focus(); } this.hidden = true">Копия</button>
    </x-mail.recipients>
    <x-mail.recipients name="cc" label="Копия" :value="$defaults['cc']" :url="$base.'/addresses'" :names="$names" :hidden="! $ccOpen"/>
    @if ($templates->isNotEmpty() && $mode === 'new')
        <div class="compose-row">
            <span class="compose-label">Шаблон</span>
            <div class="flex min-w-0 flex-1 gap-2 overflow-x-auto py-1">
                @foreach ($templates as $t)
                    <x-ui.pill :href="request()->fullUrlWithQuery(['template' => $t->id])" :current="request()->query('template') == $t->id" :data-turbo-frame="'letters-frame'">{{ $t->name }}</x-ui.pill>
                @endforeach
            </div>
        </div>
    @endif
    <div class="compose-row">
        <label for="compose-subject-{{ $frame ?? 'page' }}" class="compose-label">Тема</label>
        <input id="compose-subject-{{ $frame ?? 'page' }}" name="subject" value="{{ old('subject', $defaults['subject']) }}" class="compose-input" autocomplete="off">
    </div>
    <div class="compose-body">
        <x-ui.editor name="body" :value="$defaults['body']" :focus="$parent && ($defaults['quote'] ?? null) === 'reply'" bare/>
        {{-- Исходное письмо — не в поле ответа, а свёрнутым под ним (как в Gmail): при отправке его приклеивает
             `Composer::create`, снятая галка — ответ без цитаты. --}}
        @if ($parent && ! empty($defaults['quote']))
            <details class="compose-quote">
                <summary class="flex items-center gap-2">
                    <label class="check" onclick="event.stopPropagation()"><input type="checkbox" name="quote" value="{{ $defaults['quote'] }}" @checked(old('quote', $errors->any() ? null : $defaults['quote']) !== null)><span>{{ $defaults['quote'] === 'forward' ? 'Пересылаемое письмо' : 'Исходное письмо' }}</span></label>
                    <x-ui.chevron class="compose-quote-chev"/>
                </summary>
                <div class="compose-quote-body trix-content">{!! app(\App\Mail\Composer::class)->withQuote('', $parent, $defaults['quote']) !!}</div>
            </details>
        @endif
        <div id="compose-files" class="compose-files" data-controller="files">
            @if ($parent && !empty($defaults['forward']))
                @foreach ($parent->files() as $file)
                    <label class="compose-file check"><input type="checkbox" name="forward[]" value="{{ $file->id }}" checked><span class="truncate">{{ $file->filename }}</span><span class="text-ink-muted">{{ $file->humanSize() }}</span></label>
                @endforeach
            @endif
            @foreach (old('files', $defaults['files'] ?? []) as $path)
                @include('admin.mail.file-row', ['path' => $path, 'name' => substr(basename($path), 37)])
            @endforeach
        </div>
        <input type="file" multiple class="sr-only" tabindex="-1" data-photos-target="input" data-action="change->photos#upload">
        <div hidden data-photos-target="progress" class="mt-2">
            <div class="mb-1 text-sm text-ink-muted" data-label></div>
            <div class="h-1.5 overflow-hidden rounded-full bg-surface-3"><div class="h-full bg-accent transition-[width]" data-bar style="width:0"></div></div>
        </div>
    </div>
    <div class="compose-foot">
        <x-ui.button size="sm">Отправить</x-ui.button>
        <button type="button" class="compose-tool" data-action="photos#pick" aria-label="Приложить файл" title="Приложить файл"><x-ui.icon name="clip" class="size-5"/></button>
        <button type="button" class="compose-tool compose-format" aria-label="Форматирование" title="Форматирование"
                onclick="this.closest('.compose').toggleAttribute('data-format'); this.classList.toggle('is-on')">Aa</button>
        {{-- Новое письмо без ветки — «Отмена» закрывает окно (`letters:close`). --}}
        @if ($cancel)<a href="{{ $cancel[0] }}" class="compose-cancel" data-turbo-frame="{{ $cancel[1] }}">Отмена</a>@else<button type="button" class="compose-cancel" data-controller="emit" data-action="emit#send" data-emit-event-param="letters:close">Отмена</button>@endif
    </div>
</div>
