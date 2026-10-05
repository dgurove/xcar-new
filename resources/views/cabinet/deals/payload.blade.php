{{-- Поля, что вписали на шаге сотрудники (дата, адрес площадки…), кроме показанных в получении. --}}
@if ($payload->isNotEmpty())
    <dl class="mt-4 grid grid-cols-1 gap-x-8 gap-y-3 sm:grid-cols-2">
        @foreach ($payload as $k => $v)
            <div class="min-w-0"><dt class="text-sm text-ink-dim">{{ collect($position->stage->staff_fields)->firstWhere('key', $k)['label'] ?? $k }}</dt><dd class="nums mt-0.5 break-words font-normal">{{ $v }}</dd></div>
        @endforeach
    </dl>
@endif
