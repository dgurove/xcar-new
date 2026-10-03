<?php

namespace App\Support\Facets;

use App\Cars\Category;
use App\Users\User;
use App\Vendors\Vendor;

/** Вариант чипа: подпись, сколько строк списка с ним и что нарисовать перед подписью (логотип, аватар, тип ТС). */
final class Option
{
    public function __construct(
        public string $key,
        public string $label,
        public int $count = 0,
        public bool $selected = false,
        public ?Vendor $vendor = null,
        public ?User $user = null,
        public ?Category $category = null,
        public ?string $hint = null,
    ) {}
}
