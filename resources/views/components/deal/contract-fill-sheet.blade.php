{{-- «Заполнить договор» (19.11.2026, вариант Б владельца): 1) скачать Word с номером договора (`dkp.docx?edit=1`), 2) приложить
     заполненный (`EditContract`). Один кусок готового (`x-deal.contract-doc`) и неготового договора (`x-deal.contract-gaps-sheet`;
     23.11.2026, владелец: «мало ли что бывает» — шаблон с прочерками заполняют руками). --}}
@props(['id', 'base', 'name'])
@php use App\Offers\ContractEdit; @endphp
<div class="contents" data-controller="sheet" data-action="{{ $id }}:open@window->sheet#open">
    <x-ui.sheet :id="$id" title="Заполнить договор">
        <form method="post" action="{{ $base }}/contract/edited" enctype="multipart/form-data" class="flex flex-col gap-4">
            @csrf
            <input type="hidden" name="_sheet" value="{{ $id }}">
            <div class="edit-step">
                <span class="edit-step-n">1</span>
                <div class="min-w-0 flex-1">
                    <div>Скачайте Word и заполните</div>
                    {{-- Строкой документа сделки (`x-deal.doc-row`, как «Счёт за ТС» рядом; 20.11.2026, владелец: чип «⤓ ДКП … .docx» — «уродство»). --}}
                    <div class="list mt-2">
                        <x-deal.doc-row :href="$base.'/dkp.docx?edit=1'" :title="$name" :name="$name.'.docx'" mime="application/vnd.openxmlformats-officedocument.wordprocessingml.document">
                            <x-slot:dl><a href="{{ $base }}/dkp.docx?edit=1" download class="btn btn-ghost btn-s px-1.5 text-ink-muted" aria-label="Скачать" title="Скачать" data-turbo="false" data-turbo-prefetch="false" data-controller="file" data-action="file#share" data-file-name="{{ $name }}.docx"><x-ui.icon name="download" class="size-5"/></a></x-slot:dl>
                        </x-deal.doc-row>
                    </div>
                </div>
            </div>
            <div class="edit-step">
                <span class="edit-step-n">2</span>
                <div class="min-w-0 flex-1">
                    <div>Приложите заполненный</div>
                    {{-- Вид поля, как `x-ui.file-field` (системного «Choose File» нет), со своим id: на странице полей файла много. --}}
                    <div class="field mt-2 @error('file') field-invalid @enderror" data-controller="file-name">
                        <label for="{{ $id }}-file" class="field-input flex cursor-pointer items-center gap-2">
                            <x-ui.icon name="clip" class="size-4 shrink-0 text-ink-dim"/>
                            <span class="min-w-0 truncate text-ink-muted" data-file-name-target="label">Выбрать файл .docx или .pdf</span>
                        </label>
                        <input id="{{ $id }}-file" name="file" type="file" accept="{{ ContractEdit::ACCEPT }}" required class="sr-only" data-action="change->file-name#show">
                        @error('file')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                </div>
            </div>
            <x-ui.button type="submit" class="w-full">Сохранить</x-ui.button>
        </form>
    </x-ui.sheet>
</div>
