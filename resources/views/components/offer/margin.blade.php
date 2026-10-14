{{-- Наценка над закупочной — только в CRM (`Offers\Margin`, 14.10.2026): «в минус 59 200» красным, «+4 600» оранжевым.
     Менеджерам и покупателям закупочной не видно — вне CRM и не админу метка не рисуется. --}}
@props(['m' => null])
@if ($m && \App\Support\Surface::current() === \App\Support\Surface::Crm && auth()->user()?->canManageCrm())
<span {{ $attributes->class(['ml-1.5 shrink-0 whitespace-nowrap text-sm nums', $m['tone'] === 'loss' ? 'text-danger' : 'text-urgent']) }} title="{{ $m['tone'] === 'loss' ? 'Цена продажи не выше закупочной' : 'Наценка меньше 5 %' }}">{{ $m['tone'] === 'loss' ? 'в минус '.\App\Support\Money::nums(abs($m['diff'])) : '+'.\App\Support\Money::nums($m['diff']) }}</span>
@endif
