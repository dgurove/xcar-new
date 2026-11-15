{{-- Подписанный договор, сделанный вне системы (15.11.2026, владелец: «ОБЯЗАТЕЛЬНО возможность прикрепить подписанный договор
     сразу, без покупателя» — и менеджеру на сайте, и сотрудникам в CRM): файлы сделки (`signed_contract`) строками с ×, ниже —
     «Приложить подписанный договор» (есть файлы — «Приложить ещё»): выбор файлов, отправка сразу. Первый файл — «договор
     подписан» (`AttachSignedContract`). base — адрес сделки на этой поверхности (`/deals/5`, `/work/deals/5`); bare — строки без
     своей группы и заголовка (внутри группы «Деньги» CRM), файл подписан «подписанный договор»; card — карточкой, как «Договор»
     окна модератора. --}}
@props(['deal', 'base', 'bare' => false, 'card' => false])
@php
    $files = $deal->getMedia('signed_contract');
    $can = \App\Offers\Actions\AttachSignedContract::allows($deal);
@endphp
@if ($files->isNotEmpty() || $can)
    @php ob_start(); @endphp
        @foreach ($files as $media)
            <x-ui.file :name="\App\Media\DocName::of($media)" :mime="$media->mime_type" :bytes="$media->size" href="/files/{{ $media->id }}" class="row" :refs="[$deal->offer?->claim_ref]" :size="$bare ? 'подписанный договор' : null">
                @if ($can)
                    <form method="post" action="{{ $base }}/signed/{{ $media->id }}" data-turbo-confirm="Убрать файл договора?">@csrf @method('delete')<button class="btn btn-ghost btn-s px-2 text-ink-muted" aria-label="Убрать"><x-ui.icon name="x" class="size-5"/></button></form>
                @endif
            </x-ui.file>
        @endforeach
        @if ($can)
            <form method="post" action="{{ $base }}/signed" enctype="multipart/form-data" class="contents signed-upload" data-controller="autosubmit">
                @csrf
                <label class="row signed-add">
                    <input type="file" name="files[]" multiple accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.heif,.doc,.docx" class="sr-only" data-action="change->autosubmit#submit">
                    <x-ui.icon name="clip" class="size-5 shrink-0"/>
                    <span class="min-w-0 flex-1">{{ $files->isEmpty() ? ($bare ? 'Приложить подписанный договор' : 'Приложить') : 'Приложить ещё' }}</span>
                </label>
            </form>
        @endif
    @php $rows = ob_get_clean(); @endphp
    @if ($bare)
        {!! $rows !!}
    @elseif ($card)
        <x-ui.card title="Подписанный договор" {{ $attributes }}>
            <div class="list">{!! $rows !!}</div>
        </x-ui.card>
    @else
        <section {{ $attributes->class(['min-w-0']) }}>
            <h2 class="list-head lg:pt-0">Подписанный договор</h2>
            <div class="list">{!! $rows !!}</div>
            @error('files')<p class="field-error">{{ $message }}</p>@enderror
            @error('files.*')<p class="field-error">{{ $message }}</p>@enderror
        </section>
    @endif
@endif
