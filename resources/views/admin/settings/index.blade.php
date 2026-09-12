<x-ui.shell title="Настройки">
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-5">
        @foreach ($tiles as [$title, $value, $href])
            <x-ui.stat :value="$value" :label="$title" :href="$href"/>
        @endforeach
    </div>
</x-ui.shell>
