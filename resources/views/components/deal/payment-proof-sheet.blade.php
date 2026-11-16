{{-- «Приложить за менеджера» на шаге оплаты (10.11.2026, владелец: «чтобы админы за него могли прикрепить, если он как-то
     по-другому админам отправил»): чек кассы, скан или файл платёжки — сколько угодно файлов; уже приложенные — строками с ×.
     «Приложить» — и его ответ шага за него (`PaymentProofController`), отдельной кнопки «шаг пройден» нет (16.11.2026,
     владелец: «сразу должна быть кнопка отправить без этого дебильного этапа»); снимки ложатся PDF, страховой уйдёт одним PDF. --}}
@props(['id', 'deal', 'requirement'])
@php $files = $requirement->getMedia('files'); $mine = old('_sheet') === $id; @endphp
<x-ui.sheet :id="$id" title="Документ об оплате">
    @if ($files->isNotEmpty())
        <div class="list mb-4">
            @foreach ($files as $f)
                <div class="row items-center gap-3">
                    <a href="/files/{{ $f->id }}" target="_blank" class="flex min-w-0 flex-1 items-center gap-3"><x-ui.file-icon :name="$f->file_name" class="file-icon-s"/><span class="min-w-0 flex-1 truncate">{{ $f->file_name }}</span></a>
                    <form method="post" action="/work/deals/{{ $deal->id }}/payment-proof/{{ $f->id }}" class="contents">@csrf @method('delete')<button class="btn btn-ghost btn-s -my-1 px-1.5 text-ink-muted" aria-label="Убрать файл"><x-ui.icon name="x" class="size-5"/></button></form>
                </div>
            @endforeach
        </div>
    @endif
    <form method="post" action="/work/deals/{{ $deal->id }}/payment-proof" enctype="multipart/form-data" class="flex flex-col gap-4">
        @csrf<input type="hidden" name="pass" value="1">
        @if ($mine && $errors->first('payment_proof'))<x-ui.flash tone="danger">{{ $errors->first('payment_proof') }}</x-ui.flash>@endif
        <div class="fields">
            <x-ui.file-field name="files[]" :label="$files->isEmpty() ? 'Чек, скан или файл платёжки' : 'Добавить ещё'" accept=".pdf,.jpg,.jpeg,.png,.heic,.webp,image/*" multiple/>
        </div>
        <p class="text-sm text-ink-muted">Страховой уйдёт одним PDF</p>
        <x-ui.sheet-foot type="submit">{{ $files->isEmpty() ? 'Приложить' : 'Готово' }}</x-ui.sheet-foot>
    </form>
</x-ui.sheet>
