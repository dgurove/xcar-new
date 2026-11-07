<?php

namespace App\Mail\Scan;

/**
 * Скан страницы паспорта (07.11.2026): `ocr` получает его под именем «pass-…» — как фото документа и ещё поперёк страницы:
 * серия и номер напечатаны вертикально (`deploy/bin/ocr`, `PassportText::ACROSS`).
 */
interface PassportPhoto extends DocumentPhoto {}
