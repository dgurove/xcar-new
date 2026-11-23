<?php

namespace Tests\Unit;

use App\Billing\ChargeKind;
use App\Billing\Invoice;
use App\Billing\InvoiceSplit;
use App\Billing\Seller;
use App\Offers\Deal;
use Tests\TestCase;

/**
 * Доли счёта в «Оплатах» сотрудника (23.11.2026, начальник: «комиссия включена в стоимость авто, и всё равно она должна
 * выделяться»): счёт ПРАЙМ — машиной до закупочной в «ДКП», остальное подбором в «Комиссиях»; неполная оплата — сначала машина.
 */
class InvoiceSplitTest extends TestCase
{
    private function sale(float $total, float $paid, Seller $seller = Seller::Prime): Invoice
    {
        return (new Invoice)->forceFill(['kind' => ChargeKind::Sale, 'seller' => $seller, 'total' => $total, 'paid' => $paid]);
    }

    private function deal(?int $cost): Deal
    {
        return (new Deal)->forceFill(['cost' => $cost]);
    }

    public function test_prime_invoice_splits_car_first(): void
    {
        $deal = $this->deal(1_350_000);
        $paid = $this->sale(1_450_000, 1_450_000);
        $this->assertTrue(InvoiceSplit::splits($paid, $deal));
        $this->assertSame([1_350_000.0, 1_350_000.0], InvoiceSplit::part($paid, $deal, 'dkp'));
        $this->assertSame([100_000.0, 100_000.0], InvoiceSplit::part($paid, $deal, 'fees'));

        // Пришло 1 400 000 — машина закрыта, подбора 50 000 из 100 000.
        $part = $this->sale(1_450_000, 1_400_000);
        $this->assertSame([1_350_000.0, 1_350_000.0], InvoiceSplit::part($part, $deal, 'dkp'));
        $this->assertSame([100_000.0, 50_000.0], InvoiceSplit::part($part, $deal, 'fees'));

        // Пришло меньше закупочной — подбор не тронут.
        $little = $this->sale(1_450_000, 300_000);
        $this->assertSame([1_350_000.0, 300_000.0], InvoiceSplit::part($little, $deal, 'dkp'));
        $this->assertSame([100_000.0, 0.0], InvoiceSplit::part($little, $deal, 'fees'));
    }

    public function test_whole_when_nothing_to_split(): void
    {
        // Без закупочной, счёт на закупочную (подбор отдельным счётом), не счёт за ТС — целиком.
        $this->assertFalse(InvoiceSplit::splits($this->sale(1_450_000, 0), $this->deal(null)));
        $this->assertFalse(InvoiceSplit::splits($this->sale(1_350_000, 0), $this->deal(1_350_000)));
        $fee = (new Invoice)->forceFill(['kind' => ChargeKind::Selection, 'seller' => Seller::Prime, 'total' => 100_000, 'paid' => 0]);
        $this->assertFalse(InvoiceSplit::splits($fee, $this->deal(1_350_000)));
        $this->assertSame([1_450_000.0, 0.0], InvoiceSplit::part($this->sale(1_450_000, 0), $this->deal(null), 'dkp'));
        $this->assertTrue(InvoiceSplit::splits($this->sale(1_450_000, 0, Seller::Ros), $this->deal(1_350_000)));
    }

    public function test_service_link_rest_goes_car_then_fee(): void
    {
        // Ссылка 1 450 000, в счета сделки ещё не легла: машине досталось 1 350 000, остальное — подбор.
        $link = (new Invoice)->forceFill(['kind' => ChargeKind::Service, 'total' => 1_450_000, 'paid' => 1_450_000])->setAttribute('credited', 0);
        $this->assertSame([1_350_000.0, 1_350_000.0], InvoiceSplit::servicePart($link, 'dkp', 1_350_000));
        $this->assertSame([100_000.0, 100_000.0], InvoiceSplit::servicePart($link, 'fees', 1_350_000));

        // Засчитано 1 222 000 в счёт за ТС, машина набрана — остаток целиком «Комиссиям».
        $over = (new Invoice)->forceFill(['kind' => ChargeKind::Service, 'total' => 1_450_000, 'paid' => 1_450_000])->setAttribute('credited', 1_122_000);
        $this->assertSame([0.0, 0.0], InvoiceSplit::servicePart($over, 'dkp', 0));
        $this->assertSame([328_000.0, 328_000.0], InvoiceSplit::servicePart($over, 'fees', 0));
    }
}
