{{-- Договор от страховой («страховой напрямую»): прислали — файлом со «Скачать», нет — «Приложить». Под подписью «Договор» над
     «Подписанным договором» (20.11.2026, владелец: «Альфа же напрямую с покупателем договор заключает — тут не наш договор, а их»),
     не в группе «Поставщику». Ждёт: $deal. --}}
@php $offer = $deal->offer; @endphp
@if (\App\Offers\SupplierContract::applies($deal))
    @php $supplierContract = \App\Offers\SupplierContract::of($offer); @endphp
    @if ($supplierContract)
        <x-deal.doc-row :href="'/files/'.$supplierContract->id" title="Договор от страховой" :name="$supplierContract->file_name" :mime="$supplierContract->mime_type" :bytes="$supplierContract->size" :file="'Договор от страховой.'.(pathinfo($supplierContract->file_name, PATHINFO_EXTENSION) ?: 'pdf')">
            {{ ($supplierContract->getCustomProperty('letter') ? 'из письма ' : 'приложен ').\App\Support\Ago::day($supplierContract->created_at) }}
        </x-deal.doc-row>
    @elseif (auth()->user()?->canEditDeals() && $deal->isActive())
        <form method="post" action="/work/deals/{{ $deal->id }}/supplier-contract" enctype="multipart/form-data" class="contents" data-controller="autosubmit">
            @csrf
            <label class="row sale-row cursor-pointer">
                <span class="sale-row-label">Договор от страховой</span>
                <span class="sale-row-value text-accent-text">Приложить</span>
                <x-ui.icon name="clip" class="size-5 shrink-0 text-ink-dim"/>
                <input type="file" name="file" accept=".pdf,.jpg,.jpeg,.png,.heic,.doc,.docx" class="sr-only" data-action="change->autosubmit#submit">
            </label>
        </form>
    @endif
@endif
