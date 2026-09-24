<?php

namespace App\Http\Admin;

use App\Http\Park\VendorContactController as ParkContacts;

/** Контакты вендора в CRM — только «Реализация»: кому пишем о предложениях. Логика общая с парковкой. */
class VendorContactController extends ParkContacts
{
    protected bool $sale = true;
}
