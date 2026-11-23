{{-- «Приложить подписанный» кнопкой словами (19.11.2026, владелец: «тупо иконка скрепки — непонятно»), и в CRM, и у менеджера.
     С 24.11.2026 — «Приложить» одним документом (`x-ui.photo-pdf`, владелец: «как в нормальном приложении»): выбор фото и файлов
     телефоном, шторка — что выбрали и название «Договор купли-продажи {марка модель}», «Сохранить» — один документ
     (`…/signed/doc`, `AttachSignedContract`). Приложили — кнопка не уходит, а становится «Приложить ещё» (владелец: «пусть она
     не уходит, просто называется „Приложить ещё“»). `primary` — лаймовой (шаг договора), иначе серой. Слот `trigger` — свой вид
     (строка «Продажи»). --}}
@props(['deal', 'base', 'primary' => false, 'label' => null, 'wide' => false, 'button' => null])
@php $more = \App\Offers\Documents\SignedContractPdf::files($deal)->isNotEmpty(); @endphp
@if (\App\Offers\Actions\AttachSignedContract::allows($deal))
    <x-ui.photo-pdf :id="'signed-'.$deal->id.'-'.substr(md5($base.($label ?? '').($button ?? '')), 0, 6)" :url="$base.'/signed/doc'" title="Договор купли-продажи" :name="'Договор купли-продажи '.$deal->offer->title()" icon="clip" word always
        :label="$more ? 'Приложить ещё' : ($label ?? 'Приложить подписанный')"
        :button="$button ?? \Illuminate\Support\Arr::toCssClasses(['btn btn-s gap-2', 'w-full' => $wide, 'btn-accent' => $primary && ! $more, 'btn-quiet' => ! $primary || $more])" {{ $attributes }}>
        @isset($trigger)<x-slot:trigger>{{ $trigger }}</x-slot:trigger>@endisset
    </x-ui.photo-pdf>
@endif
