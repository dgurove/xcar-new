{{-- Всплывающие сообщения. Узел постоянный: переходы и морф его не трогают, показанное
     не пропадает под следующим тапом. Серверный flash — отдельным <template>, который
     toast_controller читает на turbo:load / turbo:render и убирает. --}}
<div class="toasts" data-controller="toast" id="toasts" data-turbo-permanent></div>
@if (session('toast') || session('toast-danger'))
<template id="flash" data-message="{{ session('toast-danger') ?? session('toast') }}" data-kind="{{ session('toast-danger') ? 'danger' : '' }}"></template>
@endif
