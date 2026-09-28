<?php

namespace App\Http\Garage;

class CarController
{
    /** Машины в гараже: менеджеру — свои, сотруднику — все. */
    public function index()
    {
        return view('garage.cars.index');
    }
}
