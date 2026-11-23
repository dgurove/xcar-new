<?php

namespace App\Media;

use RuntimeException;

/** «Приложить» не может собрать выбранное (Word вперемешку с фото) — словами человеку, 422. */
final class DocumentRefused extends RuntimeException {}
