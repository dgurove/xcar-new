{{-- Файл документа чипом в строке списка: квадратик расширения (PDF красным, XLSX зелёным, DOCX лаймовым) и подпись
     «Счёт» / «Акт». Нажатие — шторка документов (просмотр, «Скачать» и «Поделиться» в её полосе). --}}
@props(['file'])
<x-ui.doc :doc="$file" class="doc-chip"><x-ui.file-icon :name="$file['name']" class="file-icon-xs"/>{{ $file['label'] }}</x-ui.doc>
