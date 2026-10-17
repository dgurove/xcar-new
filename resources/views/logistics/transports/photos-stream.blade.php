{{-- Ответ загрузки и правки кадров: ряд блока заново. --}}
<turbo-stream action="replace" target="transport-photos-{{ $at }}"><template><x-logistics.photos :t="$t" :at="$at" edit/></template></turbo-stream>
