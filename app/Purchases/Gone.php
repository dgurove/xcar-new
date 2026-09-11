<?php

namespace App\Purchases;

use RuntimeException;

/** Машины у поставщика больше нет: не сбой сети, повторять нечего. */
final class Gone extends RuntimeException {}
