{{-- Смахнули дело: секция уходит из списка целиком; в архив — тост с «Вернуть» (20.10.2026), обратно — просто тост. --}}
<turbo-stream action="remove" target="case-{{ $kind }}-{{ $id }}"></turbo-stream>
<turbo-stream action="toast" data-message="{{ $restore ? 'Снова во входящих' : 'В архиве' }}" @unless ($restore) data-undo-url="{{ $undo }}" data-undo-label="Вернуть" @endunless></turbo-stream>
