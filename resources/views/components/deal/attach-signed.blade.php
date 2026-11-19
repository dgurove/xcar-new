{{-- «Приложить подписанный» кнопкой словами (19.11.2026, владелец: «тупо иконка скрепки — непонятно»), и в CRM, и у менеджера:
     выбор файлов, отправка сразу (`AttachSignedContract`). Приложили — кнопка не уходит, а становится «Приложить ещё» (владелец:
     «пусть она не уходит, просто называется „Приложить ещё“»). `primary` — лаймовой (шаг договора), иначе серой. --}}
@props(['deal', 'base', 'primary' => false, 'label' => null, 'wide' => false])
@php $more = \App\Offers\Documents\SignedContractPdf::files($deal)->isNotEmpty(); @endphp
@if (\App\Offers\Actions\AttachSignedContract::allows($deal))
    <form method="post" action="{{ $base }}/signed" enctype="multipart/form-data" {{ $attributes->class('contents') }} data-controller="autosubmit">
        @csrf
        <label @class(['btn btn-s gap-2 cursor-pointer', 'w-full' => $wide, 'btn-accent' => $primary && ! $more, 'btn-quiet' => ! $primary || $more])>
            <x-ui.icon name="clip" class="size-4"/>{{ $more ? 'Приложить ещё' : ($label ?? 'Приложить подписанный') }}
            <input type="file" name="files[]" multiple accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.heif,.doc,.docx" class="sr-only" data-action="change->autosubmit#submit">
        </label>
    </form>
@endif
