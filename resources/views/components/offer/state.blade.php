@props(['state'])
@php $tone = $state->tone(); @endphp
<span {{ $attributes->merge(['class' => 'chip '.match($tone) {
    'open' => 'bg-open-soft text-open', 'urgent' => 'bg-urgent-soft text-urgent', 'danger' => 'bg-danger-soft text-danger',
    'closed' => 'bg-closed-soft text-closed', default => '' }]) }}>{{ $state->label() }}</span>
