{{-- Скан документа из снимков телефона: страница на снимок, картинка вписана в лист A4 (`ReportController::scan`). --}}
<html><head><meta charset="utf-8"><style>
    @page { margin: 0; }
    body { margin: 0; }
    .page { width: 210mm; height: 296mm; text-align: center; page-break-after: always; }
    .page:last-child { page-break-after: auto; }
    .page img { max-width: 200mm; max-height: 286mm; margin-top: 5mm; }
</style></head><body>
@foreach ($pages as $src)<div class="page"><img src="{{ $src }}"></div>@endforeach
</body></html>
