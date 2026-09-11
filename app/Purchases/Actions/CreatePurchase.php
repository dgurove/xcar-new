<?php

namespace App\Purchases\Actions;

use App\Purchases\Purchase;
use App\Purchases\PurchaseState;

final class CreatePurchase
{
    public function __invoke(array $data): Purchase
    {
        return Purchase::create(['number' => Purchase::nextNumber(), 'state' => PurchaseState::Draft] + $data);
    }
}
