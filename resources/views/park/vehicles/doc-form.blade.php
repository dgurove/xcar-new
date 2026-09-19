{{-- Одна бумага: состояние строками-радио, дата, скан, письмо, заметка. Без $doc — новая. --}}
@php use App\Park\{DocKind, DocState}; @endphp
<form method="post" action="/cars/{{ $vehicle->id }}/docs{{ $doc ? '/'.$doc->id : '' }}" enctype="multipart/form-data" class="flex flex-col gap-4">
    @csrf
    @unless ($doc)
        <x-ui.field name="kind" label="Что за бумага" :options="DocKind::options()"/>
        <x-ui.field name="direction" label="Кто кому" :options="['out' => 'Мы вендору', 'in' => 'Ждём от вендора']"/>
    @endunless
    <div class="flex flex-col gap-2">
        @foreach (DocState::cases() as $state)
            @if ($state === DocState::Received && ($doc?->isOut() ?? true) && !($doc === null))@continue @endif
            <label class="row row-check !py-2"><span class="min-w-0 flex-1">{{ $state->label() }}</span><span class="check"><input type="radio" name="state" value="{{ $state->value }}" @checked(($doc?->state ?? DocState::Pending) === $state)></span></label>
        @endforeach
    </div>
    <div class="grid grid-cols-2 gap-3">
        <x-ui.field name="at" label="Когда" type="date" :value="($doc?->at ?? now())->toDateString()"/>
        <x-ui.field name="file" label="Скан" type="file" accept=".pdf,.jpg,.jpeg,.png,.heic"/>
        @if ($threads->isNotEmpty())<x-ui.field name="thread_id" label="Письмо" :options="$threads->mapWithKeys(fn ($t) => [$t->id => \Illuminate\Support\Str::limit($t->subject ?? 'Без темы', 40)])" placeholder="—" :value="$doc?->thread_id" span="col-span-2"/>@endif
        <x-ui.field name="note" label="Заметка" :value="$doc?->note" span="col-span-2"/>
    </div>
    @if ($doc?->media)<x-ui.file :name="$doc->media->file_name" :href="'/files/'.$doc->media->id" :mime="$doc->media->mime_type"/>@endif
    @if ($doc?->user)<x-ui.person :user="$doc->user" :prefix="$doc->state->label()"/>@endif
    <x-ui.button block>Сохранить</x-ui.button>
</form>
