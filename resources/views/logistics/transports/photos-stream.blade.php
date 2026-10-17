{{-- Ответ загрузки и правки кадров: ряд блока заново. --}}
<turbo-stream action="replace" target="transport-photos-{{ $at }}"><template><x-logistics.photos :t="$t" :at="$at" edit :url="$url ?? null"/></template></turbo-stream>
<turbo-stream action="replace" target="transport-photos-{{ $at }}-view"><template><x-logistics.photos :t="$t" :at="$at" view :url="$url ?? null"/></template></turbo-stream>
