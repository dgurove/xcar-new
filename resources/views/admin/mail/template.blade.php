@php $new = !$template->exists; @endphp
<x-ui.shell :title="$new ? 'Новый шаблон' : $template->name" narrow>
    <form method="post" action="{{ $new ? '/nastroyki/shablony' : '/nastroyki/shablony/'.$template->id }}" id="template-form" class="flex flex-col gap-4">
        @csrf @unless ($new) @method('put') @endunless
        <x-ui.card>
            <div class="flex flex-col gap-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.field name="name" label="Название" :value="$template->name" required/>
                    <x-ui.field name="scope" label="Чей" :options="\App\Mail\Scope::options()" :value="$template->scope?->value"/>
                </div>
                <x-ui.field name="subject" label="Тема" :value="$template->subject"/>
                <x-ui.editor name="body" label="Письмо" :value="$template->body"/>
                <div class="flex flex-wrap gap-1.5">
                    @foreach (array_unique([...\App\Mail\Template::PLACEHOLDERS, ...\App\Mail\Template::PARK_PLACEHOLDERS]) as $p)<span class="chip font-mono text-xs">@{{ {{ $p }} }}</span>@endforeach
                </div>
            </div>
        </x-ui.card>
    </form>
    <x-ui.action-bar>
        <x-ui.button form="template-form" class="flex-1">Сохранить</x-ui.button>
        @unless ($new)<form method="post" action="/nastroyki/shablony/{{ $template->id }}" data-turbo-confirm="Удалить шаблон?">@csrf @method('delete')<x-ui.button variant="danger" round class="btn-lg"><x-ui.icon name="trash" class="size-5"/></x-ui.button></form>@endunless
    </x-ui.action-bar>
</x-ui.shell>
