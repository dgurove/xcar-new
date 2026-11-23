{{-- Ответ на загрузку и удаление документа: только список бумаг. Карточки кадров не трогаются — у документа
     стадии нет, и «та стадия, откуда пришёл запрос» к нему не применима. --}}
<turbo-stream action="replace" target="{{ ($stage ?? null) ? 'papers-'.$stage->value : 'papers' }}"><template>@include('park.vehicles.papers', ['vehicle' => $vehicle, 'papersStage' => $stage ?? null])</template></turbo-stream>
