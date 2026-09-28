<?php

namespace App\Billing\Bank\Actions;

use App\Billing\Bank\Connection;

/** Расчётный счёт, по которому грузится выписка. */
final class SetBankAccount
{
    public function __invoke(Connection $connection, string $account): Connection
    {
        $connection->update(['account' => $account]);

        return $connection;
    }
}
