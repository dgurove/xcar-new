@php
    $new = ! $template->exists;
    $ask = $template->isRequest();
    $fresh = $ask ? 'Новая просьба' : 'Новый шаблон';
    $choicesText = implode("\n", $template->choices ?? []);
@endphp
<x-ui.cabinet :title="$new ? $fresh : $template->name">
    {{-- На телефоне это имя уже стоит заголовком экрана (h1 кабинета), на ПК h1 нет — только тут. --}}
    <h2 class="text-xl max-md:hidden">{{ $new ? $fresh : $template->name }}</h2>
    <form method="post" action="{{ $new ? $base : $base.'/'.$template->id }}" id="template-form" enctype="multipart/form-data" class="flex flex-col gap-4" data-controller="save-bar" data-save-bar-dirty-value="{{ $errors->any() || $new ? 'true' : 'false' }}">
        @csrf @unless ($new) @method('put') @endunless
        {{-- Доп. запрос поставщику (17.11.2026, `Offers\ExtraRequest`): галка в окне письма и фраза после «Просьба …»; пример —
             как фраза ляжет в письмо, по ходу ввода. Город — `{{ city }}` в фразе, варианты — по строке. --}}
        @if ($ask)
            <input type="hidden" name="kind" value="request">
            <x-ui.card>
                <div class="flex flex-col gap-4">
                    <x-ui.field name="name" label="Галка" :value="$template->name" placeholder="Запросить ПТС" required/>
                    <x-ui.field name="body" label="Фраза" :value="$template->body" placeholder="прислать ПТС" required
                                oninput="this.form.querySelector('[data-sample]').textContent = this.value.trim().replace(/\x7b\x7b ?city ?\x7d\x7d/, (this.form.elements.choices.value.split('\n')[0] || '').trim()) || '…'"/>
                    <x-ui.field name="choices" label="Варианты города" type="textarea" :value="$choicesText" placeholder="в Москве"/>
                    <x-ui.check name="attach" :value="\App\Mail\Template::PAY_PROOF" :checked="$template->attach === \App\Mail\Template::PAY_PROOF">Приложить платёжное поручение</x-ui.check>
                    <div class="rounded-(--radius-m) bg-surface-2 p-3 text-ink-muted">Здравствуйте!<br><br>Просьба <span class="text-ink" data-sample>{{ $template->body ? \App\Offers\ExtraRequest::phrase(collect([$template]), $template->choices[0] ?? null) : '…' }}</span>.<br><br>{!! nl2br(e(\App\Mail\Template::OFFERS_SIGN)) !!}</div>
                </div>
            </x-ui.card>
        @else
        <x-ui.card>
            <div class="flex flex-col gap-4">
                {{-- Письмо шага (приём, выдача, счёт): название не меняется — по нему его находит `Template::park`. --}}
                @if ($template->isStep())
                    <input type="hidden" name="name" value="{{ $template->name }}">
                    @unless ($park)<input type="hidden" name="scope" value="{{ $template->scope->value }}">@endunless
                @elseif ($park)
                    <x-ui.field name="name" label="Название" :value="$template->name" required/>
                @else
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-ui.field name="name" label="Название" :value="$template->name" required/>
                        <x-ui.field name="scope" label="Чей" :options="\App\Mail\Scope::options()" :value="$template->scope?->value"/>
                    </div>
                @endif
                <x-ui.field name="subject" label="Тема" :value="$template->subject"/>
                <x-ui.editor name="body" label="Письмо" :value="$template->body"/>
                {{-- Ответу парковки: на какое письмо предлагать первым и что приложить само (`Template::replies`). --}}
                @if ($template->scope === \App\Mail\Scope::Park && ! $template->isStep())
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-ui.field name="intent" label="Предлагать первым на письмо" :options="$intents" :value="$template->intent"/>
                        <x-ui.field name="attach" label="Приложить само" :options="$attaches" :value="$template->attach"/>
                    </div>
                @endif
                {{-- Постоянные файлы письма CRM (23.11.2026: доверенность к письму о вывозе) — окно письма кладёт их во вложения. --}}
                @unless ($park)
                    <div class="flex flex-col gap-2">
                        <span class="text-sm text-ink-muted">Файлы к письму</span>
                        @foreach ($template->getMedia('files') as $f)
                            <x-ui.file :name="$f->getCustomProperty('title') ?: $f->file_name" :href="'/files/'.$f->id" :bytes="$f->size" :mime="$f->mime_type">
                                {{-- Крестик, как у всех документов: отметка «убрать» уходит с «Сохранить», строка до тех пор бледнеет. --}}
                                <label class="btn btn-ghost btn-s cursor-pointer px-1.5 text-ink-muted" aria-label="Убрать файл" onchange="this.closest('.file-row').style.opacity = this.querySelector('input').checked ? .4 : ''"><input type="checkbox" name="drop_files[]" value="{{ $f->id }}" class="sr-only"><x-ui.icon name="x" class="size-5"/></label>
                            </x-ui.file>
                        @endforeach
                        <div class="flex flex-wrap items-center gap-2"><label class="btn btn-quiet btn-s relative w-fit cursor-pointer"><input type="file" name="files[]" multiple accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.doc,.docx" class="sr-only" onchange="this.closest('div').querySelector('[data-picked]').textContent = [...this.files].map(f => f.name).join(', ')"><x-ui.icon name="plus" class="size-4"/> Добавить файл</label><span class="min-w-0 truncate text-sm text-ink-muted" data-picked></span></div>
                    </div>
                @endunless
                <div class="flex flex-wrap gap-1.5">
                    @foreach (array_unique([...\App\Mail\Template::PLACEHOLDERS, ...\App\Mail\Template::PARK_PLACEHOLDERS]) as $p)<span class="chip font-mono text-xs">&#123;&#123; {{ $p }} &#125;&#125;</span>@endforeach
                </div>
            </div>
        </x-ui.card>
        @endif
    </form>
    <x-ui.action-bar>
        <x-ui.button form="template-form" class="flex-1" data-save-bar-button data-save-bar="template-form" hidden>{{ ($new) ? 'Сохранить' : 'Сохранить изменения' }}</x-ui.button>
        @unless ($new || $template->isStep() || in_array($template->attach, \App\Mail\Template::ROUTE_ATTACH, true))<form method="post" action="{{ $base }}/{{ $template->id }}" data-turbo-confirm="{{ $ask ? 'Удалить запрос?' : 'Удалить шаблон?' }}">@csrf @method('delete')<x-ui.button variant="danger" round class="btn-lg" aria-label="Удалить"><x-ui.icon name="x" class="size-5"/></x-ui.button></form>@endunless
    </x-ui.action-bar>
</x-ui.cabinet>
