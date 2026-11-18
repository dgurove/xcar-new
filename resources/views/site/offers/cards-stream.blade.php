{{-- Плитки пачкой по живым событиям (`FragmentController::cards`): тот же поток, что у одной плитки, на каждую. --}}
@foreach ($cards as $card)
@include('site.offers.card-stream', $card)
@endforeach
