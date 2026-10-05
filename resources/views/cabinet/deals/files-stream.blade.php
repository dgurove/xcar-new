{{-- Ответ «Приложить» и удаления файла: список файлов, кнопки шага (без файла их нет) и галка пункта «Подписанный договор». --}}
<turbo-stream action="replace" target="requirement-files"><template>@include('cabinet.deals.files', ['requirement' => $requirement])</template></turbo-stream>
<turbo-stream action="replace" target="requirement-exits"><template>@include('cabinet.deals.exits', ['requirement' => $requirement, 'deal' => $deal, 'exits' => $exits])</template></turbo-stream>
<turbo-stream action="replace" target="signed-mark"><template>@include('cabinet.deals.signed-mark', ['done' => $requirement->getMedia('files')->isNotEmpty()])</template></turbo-stream>
