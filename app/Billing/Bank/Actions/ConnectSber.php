<?php

namespace App\Billing\Bank\Actions;

use App\Billing\Bank\Connection;
use App\Billing\Bank\SberApi;
use App\Users\User;

/** Директор вернулся из СберБизнес ID с кодом: обменять на токены и запомнить, кто и когда подключил. */
final class ConnectSber
{
    public function __construct(private SberApi $api) {}

    public function __invoke(Connection $connection, string $code, User $by): Connection
    {
        $this->api->exchange($connection, $code);
        $connection->update(['connected_by' => $by->id, 'connected_at' => now(), 'last_error' => null, 'failed_at' => null]);

        return $connection;
    }
}
