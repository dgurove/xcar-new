{{-- Шторка «Счёт поставщика» (`Offers\SupplierInvoice`, 10.11.2026, владелец: «прислали счёт, а как его показать менеджеру —
     непонятно»): какой документ — счёт (из писем первыми, последний PDF письма отмечен сам) или новый файл, своё сообщение;
     менеджеру он открывается «Счётом поставщика от …». С `exit` — ещё и шаг «Счёт от поставщика получен». --}}
@props(['id', 'deal', 'exit' => null])
@php
    use App\Offers\SupplierInvoice;
    $files = SupplierInvoice::candidates($deal->offer);
    $mine = old('_sheet') === $id;
    $pick = $mine ? (int) old('media') : SupplierInvoice::guess($files)?->id;
@endphp
<x-ui.sheet :id="$id" title="Счёт поставщика" tall>
    <form method="post" action="/work/deals/{{ $deal->id }}/supplier-invoice" enctype="multipart/form-data" class="flex flex-col gap-4">
        @csrf
        @if ($exit)<input type="hidden" name="exit" value="{{ $exit->id }}">@endif
        @if ($mine && ($errors->first('invoice') ?: $errors->first('disclose')))<x-ui.flash tone="danger">{{ $errors->first('invoice') ?: $errors->first('disclose') }}</x-ui.flash>@endif
        @if ($files->isNotEmpty())
        <div>
            <p class="field-label mb-2">Какой документ — счёт</p>
            <div class="list">
                @foreach ($files->take(6) as $f)
                    <label class="row items-center gap-3">
                        <span class="check"><input type="radio" name="media" value="{{ $f->id }}" @checked($pick === $f->id)></span>
                        <x-ui.file-icon :name="$f->file_name" class="file-icon-s"/>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate">{{ $f->name ?: $f->file_name }}</span>
                            <span class="block text-sm text-ink-muted">{{ SupplierInvoice::origin($f) }}</span>
                        </span>
                        <a href="/files/{{ $f->id }}" target="_blank" class="btn btn-ghost btn-s -my-1 px-1.5 text-ink-muted" aria-label="Открыть документ"><x-ui.icon name="eye" class="size-5"/></a>
                    </label>
                @endforeach
            </div>
        </div>
        @endif
        {{-- Выбрали файл — счётом идёт он, отмеченная строка выше не в счёт. --}}
        <div class="fields">
            <x-ui.file-field name="file" :label="$files->isEmpty() ? 'Файл счёта' : 'Или другой файл'" accept=".pdf,.jpg,.jpeg,.png,.heic,image/*"/>
            <x-ui.field type="textarea" name="text" label="Своё сообщение менеджеру" :id="$id.'-text'" :value="$mine ? old('text') : null" rows="2" placeholder="Оплата только от плательщика по счёту"/>
        </div>
        <x-ui.sheet-foot type="submit">{{ $exit ? 'Показать менеджеру, шаг пройден' : 'Показать менеджеру' }}</x-ui.sheet-foot>
    </form>
</x-ui.sheet>
