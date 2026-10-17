{{-- Ссылка исполнителя погашена: задачу отменили или отдали другому. --}}
<x-pickup.layout title="Ссылка не действует" :home="false">
    <div class="flex flex-1 flex-col items-center justify-center pb-16 text-center">
        <span class="scan-lead-icon scan-lead-icon--danger"><x-ui.icon name="x" class="size-7"/></span>
        <h1 class="mt-4 text-2xl">Ссылка больше не действует</h1>
        <p class="mt-2 text-ink-muted">Задачу отменили или передали другому, уточните у логиста</p>
    </div>
</x-pickup.layout>
