<x-ui.shell :title="match($mode) { 'reply' => 'Ответ', 'all' => 'Ответ всем', 'forward' => 'Пересылка', default => 'Новое письмо' }" narrow>
    <form method="post" action="{{ $base }}" id="compose" class="flex flex-col gap-4" data-controller="photos draft" data-photos-url-value="{{ $base }}/file">
        @csrf
        @if ($parent)<input type="hidden" name="parent" value="{{ $parent->id }}">@endif
        @if ($thread)<input type="hidden" name="thread" value="{{ $thread->id }}">@endif
        @if ($offer)<input type="hidden" name="offer" value="{{ $offer->id }}">@endif
        @if ($vehicle ?? null)<input type="hidden" name="vehicle" value="{{ $vehicle->id }}">@endif
        @if ($back ?? null)<input type="hidden" name="back" value="{{ $back }}">@endif
        @if ($invoice ?? null)<input type="hidden" name="invoice" value="{{ $invoice->id }}">@endif
        <x-ui.card>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                @if ($accounts->count() > 1)
                    <x-ui.field name="account" label="От кого" :options="$accounts->pluck('title', 'slug')" :value="$account->slug" span="sm:col-span-2"/>
                @else
                    <input type="hidden" name="account" value="{{ $account->slug }}">
                    <div class="text-sm text-ink-muted sm:col-span-2">От: {{ $account->fromName() }} &lt;{{ $account->email }}&gt;</div>
                @endif
                <x-ui.field name="to" label="Кому" type="email" multiple :value="$defaults['to']" autocomplete="off" required/>
                <x-ui.field name="cc" label="Копия" :value="$defaults['cc']" autocomplete="off"/>
                <x-ui.field name="subject" label="Тема" :value="$defaults['subject']" span="sm:col-span-2"/>
                @if ($templates->isNotEmpty() && $mode === 'new')
                    <div class="field sm:col-span-2">
                        <span class="field-label">Шаблон</span>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($templates as $t)
                                <x-ui.pill :href="request()->fullUrlWithQuery(['template' => $t->id])" :current="request()->query('template') == $t->id">{{ $t->name }}</x-ui.pill>
                            @endforeach
                        </div>
                    </div>
                @endif
                <div class="sm:col-span-2"><x-ui.editor name="body" label="Письмо" :value="$defaults['body']"/></div>
            </div>
        </x-ui.card>

        <x-ui.card title="Вложения">
            <div id="compose-files" class="flex flex-col gap-1" data-controller="files">
                @if ($parent && !empty($defaults['forward']))
                    @foreach ($parent->files() as $file)
                        <label class="check py-1"><input type="checkbox" name="forward[]" value="{{ $file->id }}" checked><span class="truncate">{{ $file->filename }} <span class="text-sm text-ink-muted">{{ $file->humanSize() }}</span></span></label>
                    @endforeach
                @endif
                @foreach (old('files', $defaults['files'] ?? []) as $path)
                    @include('admin.mail.file-row', ['path' => $path, 'name' => substr(basename($path), 37)])
                @endforeach
            </div>
            <input type="file" multiple hidden data-photos-target="input" data-action="change->photos#upload">
            <div hidden data-photos-target="progress" class="my-2">
                <div class="mb-1 text-sm text-ink-muted" data-label></div>
                <div class="h-1.5 overflow-hidden rounded-full bg-surface-3"><div class="h-full bg-accent transition-[width]" data-bar style="width:0"></div></div>
            </div>
            <x-ui.button type="button" variant="secondary" size="sm" class="mt-2" data-action="photos#pick"><x-ui.icon name="clip" class="size-4"/> Приложить</x-ui.button>
        </x-ui.card>
    </form>
    <x-ui.action-bar>
        <x-ui.button form="compose" class="min-w-0 flex-1">Отправить</x-ui.button>
    </x-ui.action-bar>
</x-ui.shell>
