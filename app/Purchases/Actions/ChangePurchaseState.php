<?php

namespace App\Purchases\Actions;

use App\Purchases\Purchase;
use App\Purchases\PurchaseState;
use App\Users\Role;
use App\Users\User;
use Illuminate\Validation\ValidationException;

final class ChangePurchaseState
{
    public function __invoke(Purchase $purchase, PurchaseState $next, User $by): Purchase
    {
        $allowed = match ($purchase->state) {
            PurchaseState::Draft => [PurchaseState::Open, PurchaseState::Archived],
            PurchaseState::Open => [PurchaseState::Closed, PurchaseState::Draft],
            PurchaseState::Closed => [PurchaseState::Open, PurchaseState::Archived],
            PurchaseState::Archived => [PurchaseState::Draft],
        };
        if (! in_array($next, $allowed, true)) {
            throw ValidationException::withMessages(['state' => "Из «{$purchase->state->label()}» нельзя в «{$next->label()}»"]);
        }
        if ($next === PurchaseState::Open && ! $purchase->cars()->exists()) {
            throw ValidationException::withMessages(['state' => 'В закупке нет машин']);
        }
        $wasPublic = $purchase->state->isPublic();
        $purchase->update(['state' => $next]);
        if ($next === PurchaseState::Open && ! $wasPublic) {
            \Illuminate\Support\Facades\Notification::send(User::where('role', Role::Manager)->get(), new \App\Notifications\PurchaseOpenedNotice($purchase));
        }
        app(\App\Live\Publisher::class)->refresh(\App\Live\Topics::CATALOG, ['/zakupki', "/zakupki/{$purchase->number}"]);

        return $purchase;
    }
}
