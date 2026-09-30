{{-- Состояние словом, цветом тона («оплачен», «просрочен», «ждёт решения»): не капсула — капсула только у того, что
     нажимается (x-ui.pill с href). tone: open, urgent, closed, danger, accent, soft, plain. --}}
@props(['tone' => 'plain'])
<span {{ $attributes->merge(['class' => 'state state-'.($tone ?: 'plain')]) }}>{{ $slot }}</span>
