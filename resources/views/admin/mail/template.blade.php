@php $new = !$template->exists; @endphp
<x-ui.cabinet :title="$new ? 'Новый шаблон' : $template->name">
    {{-- На телефоне это имя уже стоит заголовком экрана (h1 кабинета), на ПК h1 нет — только тут. --}}
    <h2 class="text-xl max-md:hidden">{{ $new ? 'Новый шаблон' : $template->name }}</h2>
    <form method="post" action="{{ $new ? '/settings/templates' : '/settings/templates/'.$template->id }}" id="template-form" class="flex flex-col gap-4" data-controller="save-bar" data-save-bar-dirty-value="{{ $errors->any() || $new ? 'true' : 'false' }}">
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
                    @foreach (array_unique([...\App\Mail\Template::PLACEHOLDERS, ...\App\Mail\Template::PARK_PLACEHOLDERS]) as $p)<span class="chip font-mono text-xs">&#123;&#123; {{ $p }} &#125;&#125;</span>@endforeach
                </div>
            </div>
        </x-ui.card>
    </form>
    <x-ui.action-bar>
        <x-ui.button form="template-form" class="flex-1" data-save-bar-button data-save-bar="template-form" hidden>{{ ($new) ? 'Сохранить' : 'Сохранить изменения' }}</x-ui.button>
        @unless ($new)<form method="post" action="/settings/templates/{{ $template->id }}" data-turbo-confirm="Удалить шаблон?">@csrf @method('delete')<x-ui.button variant="danger" round class="btn-lg"><x-ui.icon name="trash" class="size-5"/></x-ui.button></form>@endunless
    </x-ui.action-bar>
</x-ui.cabinet>
