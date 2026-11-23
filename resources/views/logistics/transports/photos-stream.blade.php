{{-- Ответ загрузки и правки кадров: ряд блока заново. --}}
<turbo-stream action="replace" target="transport-photos-{{ $at }}"><template><x-logistics.photos :t="$t" :at="$at" edit :url="$url ?? null"/></template></turbo-stream>
<turbo-stream action="replace" target="transport-photos-{{ $at }}-view"><template><x-logistics.photos :t="$t" :at="$at" view :url="$url ?? null"/></template></turbo-stream>
{{-- Окно вывоза держит кадры вывоза пачкой в блоке файлов (`x-logistics.files`) — его целиком; страница исполнителя по ссылке (без
     входа) блока нет. --}}
@auth
<turbo-stream action="replace" target="transport-files-{{ $t->id }}"><template><x-logistics.files :t="$t"/></template></turbo-stream>
@endauth
