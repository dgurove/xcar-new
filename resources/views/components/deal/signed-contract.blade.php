{{-- Подписанный договор, сделанный вне системы (15.11.2026, владелец: «ОБЯЗАТЕЛЬНО возможность прикрепить подписанный договор
     сразу, без покупателя» — и менеджеру на сайте, и сотрудникам в CRM): файлы сделки (`signed_contract`) строками с ×, ниже —
     «Приложить подписанный договор» (есть файлы — «Приложить ещё»): выбор файлов, отправка сразу. Первый файл — «договор
     подписан» (`AttachSignedContract`). base — адрес сделки на этой поверхности (`/deals/5`, `/work/deals/5`); bare — строки без
     своей группы и заголовка (внутри группы «Деньги» CRM), файл подписан «подписанный договор»; card — карточкой, как «Договор»
     окна модератора. --}}
@props(['deal', 'base', 'bare' => false, 'card' => false, 'label' => 'Подписанный договор', 'ask' => false])
@php
    $files = $deal->getMedia('signed_contract');
    $can = \App\Offers\Actions\AttachSignedContract::allows($deal);
@endphp
@if ($ask && $files->isEmpty() && $can)
    {{-- Менеджеру на сайте — вопросом своей карточкой (16.11.2026, владелец: «договор и счёт сформируем мы по данным
         покупателя, а если уже подписали — можно приложить, разделить эти понятия, а то будут просить скинуть договор»). --}}
    <section {{ $attributes->class(['list deal-form min-w-0']) }}>
        <form method="post" action="{{ $base }}/signed" enctype="multipart/form-data" class="contents signed-upload" data-controller="autosubmit">
            @csrf
            <label class="row sale-row signed-add">
                <input type="file" name="files[]" multiple accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.heif,.doc,.docx" class="sr-only" data-action="change->autosubmit#submit">
                <span class="sale-row-label"><span class="block">Договор уже подписан?</span><span class="block text-sm text-ink-muted">Если подписали без нас</span></span>
                <span class="sale-row-value text-accent-text">Приложить</span><x-ui.icon name="clip" class="size-5 shrink-0 text-ink-dim"/>
            </label>
        </form>
        @error('files')<p class="field-error">{{ $message }}</p>@enderror
        @error('files.*')<p class="field-error">{{ $message }}</p>@enderror
    </section>
@elseif ($files->isNotEmpty() || $can)
    @php ob_start(); @endphp
        @foreach ($files as $media)
            {{-- Тем же видом, что счета и договор (16.11.2026, `x-deal.doc-row`): «Подписанный договор от 16.11», «Скачать», крестик. --}}
            <x-deal.doc-row :href="'/files/'.$media->id" title="Подписанный договор" :date="\App\Support\Ago::day($media->created_at)" :name="$media->file_name" :mime="$media->mime_type" :bytes="$media->size" :file="\App\Media\DocName::of($media)">
                @if ($can)
                    <x-slot:acts>
                        <form method="post" action="{{ $base }}/signed/{{ $media->id }}" data-turbo-confirm="Убрать файл договора?">@csrf @method('delete')<button class="btn btn-ghost btn-s px-2 text-ink-muted" aria-label="Убрать"><x-ui.icon name="x" class="size-5"/></button></form>
                    </x-slot:acts>
                @endif
            </x-deal.doc-row>
        @endforeach
        @if ($can)
            <form method="post" action="{{ $base }}/signed" enctype="multipart/form-data" class="contents signed-upload" data-controller="autosubmit">
                @csrf
                <label @class(['row signed-add', 'sale-row' => $bare && $files->isEmpty()])>
                    <input type="file" name="files[]" multiple accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.heif,.doc,.docx" class="sr-only" data-action="change->autosubmit#submit">
                    {{-- Строкой «Продажи» «Подписанный договор — Приложить» (16.11.2026, `x-deal.sale-row`). --}}
                    @if ($bare && $files->isEmpty())
                        {{-- «Приложить» зелёным по краю значений других строк, серая скрепка — на месте «›» (16.11.2026, владелец). --}}
                        <span class="sale-row-label">{{ $label }}</span><span class="sale-row-value text-accent-text">Приложить</span><x-ui.icon name="clip" class="size-5 shrink-0 text-ink-dim"/>
                    @else
                        <x-ui.icon name="clip" class="size-5 shrink-0"/>
                        <span class="min-w-0 flex-1">{{ $files->isEmpty() ? 'Приложить' : 'Приложить ещё' }}</span>
                    @endif
                </label>
            </form>
        @endif
    @php $rows = ob_get_clean(); @endphp
    @if ($bare)
        {!! $rows !!}
    @elseif ($card || $ask)
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
