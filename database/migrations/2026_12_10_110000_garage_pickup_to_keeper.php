<?php

use App\Garage\Actions\PickupToKeeper;
use App\Garage\Car;
use App\Garage\CarState;
use App\Users\Role;
use App\Users\User;
use Illuminate\Database\Migrations\Migration;

/**
 * Машина в гараже, а вывоз остался прежним — «к нам» или на парковку (05.10.2026, Бородин, Jetour T1). Не забранный
 * ещё вывоз таких машин — к их менеджеру; кто везёт, не трогаем.
 */
return new class extends Migration
{
    public function up(): void
    {
        $by = User::withRole(Role::Admin)->orderBy('id')->first();
        if (! $by) {
            return;
        }
        Car::whereIn('state', [CarState::Waiting, CarState::Delivery])->whereNotNull('manager_id')->with('offer')->get()
            ->each(fn (Car $car) => $car->offer && app(PickupToKeeper::class)($car->offer, $by));
    }

    public function down(): void {}
};
