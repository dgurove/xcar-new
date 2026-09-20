{{-- Кто сдал / получил и подпись пальцем — последняя карточка формы приёма и выдачи, после фото. --}}
@php $signer ??= 'Кто сдал'; @endphp
<x-ui.card :title="$signer">
    <div class="grid grid-cols-2 gap-3">
        <x-ui.field name="signer_name" label="Имя" span="col-span-full"/>
        <x-park.signature/>
    </div>
</x-ui.card>
