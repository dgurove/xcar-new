{{-- Искра читалки «Завести» у правого края блока «Документы»: прочитать документы заново — прочитанное берётся из
     кеша сразу, новое (загрузили, пришло письмо) дочитывается. Пока читается — колдует. --}}
<button type="button" {{ $attributes->merge(['class' => 'btn btn-s btn-quiet btn-round scan-spark ml-auto shrink-0']) }} data-action="reader#read" data-reader-target="spark" aria-label="Заполнить из документов" title="Заполнить из документов"><x-ui.spark class="size-5"/></button>
