{{-- «Выставить» комиссию (18.11.2026, владелец): ссылка уходит в чат менеджеру (`ShowToManager::toChat`), видна в сделке и сразу в
     буфере обмена (`issue_controller`). block — кнопкой во всю ширину (в «Продаже» и шторке шага), иначе — маленькой в строке. --}}
@props(['deal', 'invoice', 'block' => false])
@if ($deal->buyer_id && auth()->user()?->canEditDeals())
    <button type="button" {{ $attributes->class(['btn btn-accent', 'btn-s w-full gap-2' => $block, 'btn-xs' => ! $block]) }} data-controller="issue"
        data-issue-url-value="/work/deals/{{ $deal->id }}/invoices/{{ $invoice->id }}/issue" data-action="issue#issue">@if ($block)<x-ui.icon name="send" class="size-4"/>@endif{{ 'Выставить' }}</button>
@endif
