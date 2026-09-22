<?php

namespace App\Cars;

use Illuminate\Support\Facades\Cache;

/**
 * Машина по имени из свободного текста: страховые пишут «Белджи», «хэнде крета», «митсу аутлендер», «фольц поло»,
 * «Мини COUNTRYMAN», «Changan CS35 Plus». Справочник знает латинское имя и русское (`name_ru`), словарь ниже —
 * разговорные и сокращённые написания. `find()` ищет в строке первую марку и следующие за ней слова как модель
 * (до госномера, VIN, года, скобки или тире) и возвращает канонические имена из справочника.
 */
final class Names
{
    /** Разговорное написание → slug марки. Многословные ключи сравниваются первыми. */
    private const BRANDS = [
        'хэнде' => 'hyundai', 'хендэ' => 'hyundai', 'хендай' => 'hyundai', 'хундай' => 'hyundai', 'хюндай' => 'hyundai', 'хёндэ' => 'hyundai',
        'мерс' => 'mercedes', 'мерседес' => 'mercedes', 'мерседес-бенц' => 'mercedes', 'мерседес бенц' => 'mercedes', 'mercedes' => 'mercedes', 'mercedes benz' => 'mercedes',
        'фольц' => 'volkswagen', 'фольксваген' => 'volkswagen', 'фольцваген' => 'volkswagen', 'фольксфаген' => 'volkswagen', 'вв' => 'volkswagen', 'vw' => 'volkswagen',
        'митсу' => 'mitsubishi', 'мицубиси' => 'mitsubishi', 'митсубиси' => 'mitsubishi', 'мицубиши' => 'mitsubishi',
        'бмв' => 'bmw', 'чери' => 'chery', 'черри' => 'chery', 'эксид' => 'exeed', 'экзид' => 'exeed',
        'лисян' => 'lixiang', 'лисянг' => 'lixiang', 'ли ауто' => 'lixiang', 'li auto' => 'lixiang', 'li' => 'lixiang',
        'тенет' => 'tenet', 'хавал' => 'haval', 'хавейл' => 'haval', 'хавейль' => 'haval', 'чанган' => 'changan', 'белджи' => 'belgee', 'белжи' => 'belgee',
        'вольво' => 'volvo', 'рено' => 'renault', 'опель' => 'opel', 'ауди' => 'audi', 'мини' => 'mini',
        'лада' => 'lada', 'ваз' => 'lada', 'танк' => 'tank', 'газ' => 'gaz', 'газель' => 'gaz', 'соболь' => 'gaz',
        'шкода' => 'skoda', 'киа' => 'kia', 'ниссан' => 'nissan', 'форд' => 'ford', 'тойота' => 'toyota', 'тоёта' => 'toyota', 'лексус' => 'lexus',
        'хонда' => 'honda', 'мазда' => 'mazda', 'субару' => 'subaru', 'сузуки' => 'suzuki', 'джили' => 'geely', 'джилли' => 'geely', 'омода' => 'omoda',
        'джаку' => 'jaecoo', 'джейку' => 'jaecoo', 'джеку' => 'jaecoo', 'москвич' => 'moskvich', 'порш' => 'porsche', 'порше' => 'porsche',
        'рендж ровер' => 'land-rover', 'ренж ровер' => 'land-rover', 'ленд ровер' => 'land-rover', 'лэнд ровер' => 'land-rover', 'range rover' => 'land-rover',
        'инфинити' => 'infiniti', 'линк' => 'lynk-co', 'линк энд ко' => 'lynk-co', 'lynk' => 'lynk-co', 'lynk&co' => 'lynk-co',
        'камаз' => 'kamaz', 'уаз' => 'uaz', 'вояж' => 'voyah', 'аито' => 'aito', 'зикр' => 'zeekr', 'зикер' => 'zeekr', 'джетур' => 'jetour', 'джетта' => 'jetta',
        'хонгци' => 'hongqi', 'хунци' => 'hongqi', 'тонар' => 'tonar', 'пежо' => 'peugeot', 'ситроен' => 'citroen', 'фиат' => 'fiat', 'шевроле' => 'chevrolet', 'шевролет' => 'chevrolet',
        'дэу' => 'daewoo', 'дэо' => 'daewoo', 'ссангйонг' => 'ssangyong', 'санг йонг' => 'ssangyong', 'джип' => 'jeep', 'додж' => 'dodge', 'кадиллак' => 'cadillac', 'крайслер' => 'chrysler',
        'сеат' => 'seat', 'вольцваген' => 'volkswagen', 'акура' => 'acura', 'датсун' => 'datsun', 'равон' => 'ravon', 'хайма' => 'haima', 'фав' => 'faw', 'дунфэн' => 'dongfeng', 'донгфенг' => 'dongfeng',
        'байк' => 'baic', 'джак' => 'jac', 'сяоми' => 'xiaomi', 'бид' => 'byd', 'бюик' => 'buick', 'ягуар' => 'jaguar', 'альфа ромео' => 'alfa-romeo', 'смарт' => 'smart',
        // Сокращения из имён файлов актов («Альфа акт 1 воях фри 2290», «мб гле», «суб фор») и частые опечатки.
        'воях' => 'voyah', 'вояхс' => 'voyah', 'джаэко' => 'jaecoo', 'джаэка' => 'jaecoo', 'джаеко' => 'jaecoo', 'мб' => 'mercedes', 'м-б' => 'mercedes', 'm-b' => 'mercedes', 'мерсседес бенц' => 'mercedes', 'mersedes-benz' => 'mercedes', 'mersedes' => 'mercedes',
        'кроне' => 'krone', 'ситрак' => 'sitrak', 'кайи' => 'kaiyi', 'кайя' => 'kaiyi', 'суб' => 'subaru', 'lambo' => 'lamborghini', 'ламбо' => 'lamborghini', 'донфенг' => 'dongfeng', 'буд' => 'byd', 'бид' => 'byd',
        'даф' => 'daf', 'daf' => 'daf', 'ман' => 'man', 'man' => 'man', 'krone' => 'krone', 'sitrak' => 'sitrak', 'belava' => 'belava', 'rr' => 'land-rover', 'шеви' => 'chevrolet', 'кадилак' => 'cadillac', 'хэндэ' => 'hyundai', 'хенде' => 'hyundai', 'хундэ' => 'hyundai', 'митсубиши' => 'mitsubishi', 'mitsi' => 'mitsubishi',
        'фольксавен' => 'volkswagen', 'фольксвагн' => 'volkswagen', 'beelgee' => 'belgee', 'белгее' => 'belgee', 'фхавал' => 'haval', 'нисан' => 'nissan', 'nisan' => 'nissan', 'лендровер' => 'land-rover', 'ленд-ровер' => 'land-rover',
        'scoda' => 'skoda', 'шкода' => 'skoda', 'infinity' => 'infiniti', 'кия' => 'kia', 'kia' => 'kia', 'kio' => 'kia',
        // Опечатки из таблицы стоянки: «Mercedes-Benc GLE», «Mercedes-AMG», «Сhevrolet» с русской С, «Луидор».
        'mercedes-benc' => 'mercedes', 'mercedes-bens' => 'mercedes', 'mercedes-amg' => 'mercedes', 'сhevrolet' => 'chevrolet', 'луидор' => 'luidor', 'luidor' => 'luidor', 'черит' => 'chery', 'чанганг' => 'changan', 'джетур' => 'jetour', 'белава' => 'belava', 'ченлонг' => 'chenglong', 'chenglong' => 'chenglong',
    ];

    /** Разговорное написание модели → как в справочнике. */
    private const MODELS = [
        'крета' => 'Creta', 'солярис' => 'Solaris', 'туссан' => 'Tucson', 'туксон' => 'Tucson', 'санта фе' => 'Santa Fe', 'элантра' => 'Elantra', 'соната' => 'Sonata', 'палисад' => 'Palisade',
        'тиго 7 про макс' => 'Tiggo 7 Pro Max', 'тиго 7 про' => 'Tiggo 7 Pro', 'тиго 8 про макс' => 'Tiggo 8 Pro Max', 'тиго 8 про' => 'Tiggo 8 Pro', 'тиго 4' => 'Tiggo 4', 'тиго 7' => 'Tiggo 7', 'тиго 8' => 'Tiggo 8', 'тиго' => 'Tiggo',
        'джолион' => 'Jolion', 'джулион' => 'Jolion', 'ф7' => 'F7', 'ф7х' => 'F7x', 'х9' => 'H9', 'х6' => 'H6', 'даргo' => 'Dargo', 'дарго' => 'Dargo',
        'алсвин' => 'Alsvin', 'уни-к' => 'UNI-K', 'уни-т' => 'UNI-T', 'уни-в' => 'UNI-V',
        'икстреил' => 'X-Trail', 'икстрейл' => 'X-Trail', 'х-трайл' => 'X-Trail', 'х-трейл' => 'X-Trail', 'x trail' => 'X-Trail', 'кашкай' => 'Qashqai', 'альмера' => 'Almera', 'террано' => 'Terrano', 'мурано' => 'Murano',
        'аутлендер' => 'Outlander', 'лансер' => 'Lancer', 'паджеро' => 'Pajero', 'асх' => 'ASX',
        'поло' => 'Polo', 'тигуан' => 'Tiguan', 'пассат' => 'Passat', 'туарег' => 'Touareg', 'джетта' => 'Jetta', 'гольф' => 'Golf',
        'октавия' => 'Octavia', 'рапид' => 'Rapid', 'кодиак' => 'Kodiaq', 'карок' => 'Karoq', 'суперб' => 'Superb',
        'гранта' => 'Granta', 'веста' => 'Vesta', 'ларгус' => 'Largus', 'нива' => 'Niva', 'калина' => 'Kalina', 'приора' => 'Priora', 'хрей' => 'XRAY',
        'куга' => 'Kuga', 'фокус' => 'Focus', 'мондео' => 'Mondeo', 'эксплорер' => 'Explorer', 'транзит' => 'Transit',
        'астра' => 'Astra', 'корса' => 'Corsa', 'инсигния' => 'Insignia', 'мокка' => 'Mokka',
        'соренто' => 'Sorento', 'спортейдж' => 'Sportage', 'рио' => 'Rio', 'сид' => 'Ceed', 'оптима' => 'Optima', 'церато' => 'Cerato', 'селтос' => 'Seltos', 'к5' => 'K5',
        'макан' => 'Macan', 'кайен' => 'Cayenne', 'кайенн' => 'Cayenne', 'панамера' => 'Panamera',
        'л9' => 'L9', 'л7' => 'L7', 'л6' => 'L6', 'тхл' => 'TXL', 'лх' => 'LX', 'вх' => 'VX', 'рх' => 'RX', 'т8' => 'T8', 'т7' => 'T7',
        'х1' => 'X1', 'х3' => 'X3', 'х4' => 'X4', 'х5' => 'X5', 'х7' => 'X7',
        'аркана' => 'Arkana', 'дастер' => 'Duster', 'логан' => 'Logan', 'сандеро' => 'Sandero', 'каптур' => 'Kaptur',
        'каунтримен' => 'Countryman', 'кантримен' => 'Countryman', 'купер' => 'Cooper',
        '7er' => '7 серии', '5er' => '5 серии', '3er' => '3 серии', 'а7' => 'A7', 'а6' => 'A6', 'а4' => 'A4', 'q7' => 'Q7', 'q5' => 'Q5', 'q3' => 'Q3',
        'камри' => 'Camry', 'королла' => 'Corolla', 'рав4' => 'RAV4', 'рав 4' => 'RAV4', 'ленд крузер' => 'Land Cruiser', 'прадо' => 'Land Cruiser Prado', 'хайлендер' => 'Highlander',
        'витара' => 'Vitara', 'гранд витара' => 'Grand Vitara', 'джимни' => 'Jimny', 'сх-5' => 'CX-5', 'сх5' => 'CX-5', 'сх-9' => 'CX-9', 'форестер' => 'Forester', 'аутбек' => 'Outback',
        'газель' => 'ГАЗель', 'газель некст' => 'ГАЗель NEXT', 'соболь' => 'Соболь', 'спорт' => 'Sport', 'рендж ровер спорт' => 'Range Rover Sport', 'рендж ровер' => 'Range Rover', 'дискавери' => 'Discovery', 'дефендер' => 'Defender',
        'с450' => 'C 450', 'е200' => 'E 200', 'е220' => 'E 220', 'глс' => 'GLS', 'гле' => 'GLE', 'глц' => 'GLC', 'глк' => 'GLK', 'гла' => 'GLA', 'мл' => 'ML', 'спринтер' => 'Sprinter', 'вито' => 'Vito', 'в-класс' => 'V-Class',
        'фри' => 'Free', 'дрим' => 'Dream', 'м9' => 'M9', 'м7' => 'M7', 'м5' => 'M5',
        'фор' => 'Forester', 'сд' => 'SD', 'с7н' => 'C7H', 'джи 8' => 'J8', 'джи8' => 'J8', 'джи 7' => 'J7', 'джи7' => 'J7', 'дашинг' => 'Dashing', 'танг' => 'Tang', 'траверс' => 'Traverse', 'эскалейд' => 'Escalade',
        'сорлярис' => 'Solaris', 'соларис' => 'Solaris', 'ай30' => 'i30', 'таурег' => 'Touareg', 'велар' => 'Range Rover Velar', 'актрос' => 'Actros', 'в300д' => 'V 300 d', 'x350' => 'X 350',
    ];

    /** Модели, по которым марка ясна и без неё (имена файлов актов без марки). */
    private const MODEL_BRANDS = [
        'джулион' => 'haval', 'джолион' => 'haval', 'дарго' => 'haval', 'тигуан' => 'volkswagen', 'таурег' => 'volkswagen', 'туарег' => 'volkswagen', 'поло' => 'volkswagen', 'пассат' => 'volkswagen',
        'дашинг' => 'jetour', 'солярис' => 'hyundai', 'соларис' => 'hyundai', 'сорлярис' => 'hyundai', 'крета' => 'hyundai', 'туссан' => 'hyundai', 'элантра' => 'hyundai', 'санта фе' => 'hyundai',
        'рио' => 'kia', 'спортейдж' => 'kia', 'соренто' => 'kia', 'церато' => 'kia', 'селтос' => 'kia', 'октавия' => 'skoda', 'рапид' => 'skoda', 'кодиак' => 'skoda', 'карок' => 'skoda',
        'камри' => 'toyota', 'королла' => 'toyota', 'рав4' => 'toyota', 'рав 4' => 'toyota', 'гранта' => 'lada', 'веста' => 'lada', 'ларгус' => 'lada', 'нива' => 'lada', 'кашкай' => 'nissan', 'икстрейл' => 'nissan',
        'аутлендер' => 'mitsubishi', 'аутбек' => 'subaru', 'форестер' => 'subaru', 'алсвин' => 'changan', 'джолион' => 'haval', 'ф7' => 'haval', 'ф7х' => 'haval', 'дастер' => 'renault', 'логан' => 'renault', 'аркана' => 'renault',
        'куга' => 'ford', 'фокус' => 'ford', 'тхл' => 'exeed', 'газель' => 'gaz', 'соболь' => 'gaz', 'эскалейд' => 'cadillac', 'траверс' => 'chevrolet', 'спринтер' => 'mercedes', 'вито' => 'mercedes',
    ];

    /** Марки, которых в справочнике может не быть: как завести. */
    private const CREATE = ['krone' => 'Krone', 'sitrak' => 'Sitrak', 'daf' => 'DAF', 'man' => 'MAN', 'belava' => 'Belava', 'chenglong' => 'Chenglong', 'moskvich' => 'Москвич', 'exeed' => 'Exeed', 'tonar' => 'Тонар', 'lada' => 'Lada', 'belgee' => 'Belgee', 'tenet' => 'Tenet', 'lixiang' => 'Lixiang', 'aito' => 'Aito', 'voyah' => 'Voyah', 'jaecoo' => 'Jaecoo', 'tank' => 'Tank', 'lynk-co' => 'Lynk & Co', 'jetour' => 'Jetour', 'hongqi' => 'Hongqi', 'zeekr' => 'Zeekr', 'omoda' => 'Omoda', 'kamaz' => 'КАМАЗ', 'uaz' => 'УАЗ', 'gaz' => 'ГАЗ'];

    /** Тот же завод под другим slug в справочнике. */
    private const ALT = ['lada' => 'vaz', 'lixiang' => 'li-auto', 'lynk-co' => 'lynk-and-co', 'mercedes' => 'mercedes-benz'];

    /** Год, госномер, VIN, скобка, тире, служебные слова — на них модель заканчивается. */
    private const STOP = '/^(?:\(|[-–—]|,|;|:|фото|гос\.?|госномер|vin|вин|г\.?в\.?|(?:19|20)\d{2}|\d{2}\.\d{2}\.\d{2,4}|[АВЕКМНОРСТУХ]\d{3}[АВЕКМНОРСТУХ]{2}\d{2,3}|[АВЕКМНОРСТУХ]{2}\d{4}\d{2,3}|[A-HJ-NPR-Z0-9]{17}|на|в|по|для|от|и)$/iu';

    /**
     * Первая марка в строке и модель за ней. @return array{brand: Brand, model: ?string, before: string, after: string}|null
     */
    public static function find(string $text): ?array
    {
        // Скобки с уточнениями («TENET (Е D Е) T 7», «Nissan X-Trail (Т367ВН790)») к имени не относятся.
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/\([^)]*\)/u', ' ', $text)));
        if ($text === '') {
            return null;
        }
        $words = preg_split('/\s+/u', $text) ?: [];
        $n = count($words);
        for ($i = 0; $i < $n; $i++) {
            for ($len = 3; $len >= 1; $len--) {
                if ($i + $len > $n) {
                    continue;
                }
                $phrase = implode(' ', array_slice($words, $i, $len));
                $brand = self::brand($phrase);
                if (! $brand) {
                    continue;
                }
                [$model, $used] = self::modelAfter($brand, array_slice($words, $i + $len), $phrase);

                return [
                    'brand' => $brand,
                    'model' => $model,
                    'before' => self::clean(implode(' ', array_slice($words, 0, $i))),
                    'after' => self::clean(implode(' ', array_slice($words, $i + $len + $used))),
                ];
            }
        }
        // Марки нет, но модель говорит за неё: «Альфа акт 1 джулион 1234», «тигуан», «дашинг».
        for ($i = 0; $i < $n; $i++) {
            for ($len = 3; $len >= 1; $len--) {
                if ($i + $len > $n) {
                    continue;
                }
                $key = self::key(implode(' ', array_slice($words, $i, $len)));
                if (isset(self::MODEL_BRANDS[$key]) && ($brand = self::bySlug(self::MODEL_BRANDS[$key]))) {
                    return [
                        'brand' => $brand,
                        'model' => self::MODELS[$key] ?? implode(' ', array_slice($words, $i, $len)),
                        'before' => self::clean(implode(' ', array_slice($words, 0, $i))),
                        'after' => self::clean(implode(' ', array_slice($words, $i + $len))),
                    ];
                }
            }
        }

        return null;
    }

    /** Марка по любому написанию: словарь, потом справочник (латиница, русское имя, slug). */
    public static function brand(string $phrase): ?Brand
    {
        $key = self::key($phrase);
        if ($key === '' || mb_strlen($key) < 2) {
            return null;
        }
        if ($slug = self::BRANDS[$key] ?? null) {
            return self::bySlug($slug);
        }
        $index = self::index();
        $id = $index[$key] ?? null;

        return $id ? Brand::find($id) : null;
    }

    /**
     * Модель — слова после марки до стоп-слова, не больше четырёх: сначала словарь, потом справочник моделей марки
     * (самое длинное совпадение), иначе слова как есть. @return array{?string, int} модель и сколько слов ушло
     */
    private static function modelAfter(Brand $brand, array $rest, string $brandPhrase): array
    {
        $take = [];
        foreach ($rest as $w) {
            if (count($take) >= 4 || preg_match(self::STOP, $w)) {
                break;
            }
            $take[] = $w;
        }
        // «Соболь», «ГАЗель» — марка ГАЗ, модель в самом слове.
        if (! $take && isset(self::MODELS[self::key($brandPhrase)]) && (self::BRANDS[self::key($brandPhrase)] ?? null) === $brand->slug && self::key($brandPhrase) !== $brand->slug) {
            return [self::MODELS[self::key($brandPhrase)], 0];
        }
        for ($len = count($take); $len >= 1; $len--) {
            // Одиночная буква с цифрами — одно слово («T 7» → «T7»); «рендж ровер спорт» — модель вместе с маркой.
            $phrase = (string) preg_replace('/\b([A-Za-zА-Яа-я])\s+(\d)/u', '$1$2', implode(' ', array_slice($take, 0, $len)));
            foreach ([self::key($brandPhrase.' '.$phrase), self::key($phrase)] as $key) {
                if (isset(self::MODELS[$key])) {
                    return [self::MODELS[$key], $len];
                }
            }
            $known = $brand->models()->where(fn ($q) => $q->whereRaw('lower(name) = ?', [mb_strtolower($phrase)])->orWhereRaw('lower(name_ru) = ?', [mb_strtolower($phrase)])->orWhere('slug', Brand::slugFor($phrase)))->first();
            if ($known) {
                return [$known->name, $len];
            }
        }
        if (! $take) {
            return [null, 0];
        }
        // Незнакомая модель: одиночная буква с цифрами склеивается («T 7» → «T7»), латиница как написана, кириллица — с заглавной.
        $raw = (string) preg_replace('/\b([A-Za-zА-Яа-я])\s+(\d)/u', '$1$2', implode(' ', $take));
        $raw = self::clean((string) preg_replace('/\s+/u', ' ', $raw));

        return [$raw === '' ? null : (preg_match('/[А-Яа-я]/u', $raw) ? mb_convert_case($raw, MB_CASE_TITLE) : $raw), count($take)];
    }

    private static function key(string $s): string
    {
        return self::clean((string) preg_replace('/\s+/u', ' ', str_replace(['ё', 'Ё', '"', '«', '»'], ['е', 'Е', '', '', ''], mb_strtolower($s))));
    }

    /** Обрезать пробелы и знаки по краям — `trim` с многобайтными тире режет UTF-8. */
    public static function clean(string $s): string
    {
        return (string) preg_replace('/^[\s.,;:\-–—"«»]+|[\s.,;:\-–—"«»]+$/u', '', $s);
    }

    private static function bySlug(string $slug): ?Brand
    {
        $brand = Brand::where('slug', $slug)->first()
            ?? (isset(self::ALT[$slug]) ? Brand::where('slug', self::ALT[$slug])->first() : null)
            ?? Brand::where('slug', 'like', $slug.'-%')->first();
        if (! $brand && isset(self::CREATE[$slug])) {
            $brand = Brand::create(['slug' => $slug, 'name' => self::CREATE[$slug]]);
        }

        return $brand;
    }

    /** Все написания марок справочника → id, в кэше на час. */
    private static function index(): array
    {
        return Cache::remember('cars.names.index:'.Brand::count(), 3600, function () {
            $index = [];
            foreach (Brand::query()->get(['id', 'slug', 'name', 'name_ru']) as $b) {
                foreach (array_filter([$b->name, $b->name_ru, $b->slug]) as $name) {
                    $index[self::key($name)] = $b->id;
                    // «Lada (ВАЗ)», «Mercedes-Benz» → и без скобок, и через пробел.
                    $index[self::key((string) preg_replace('/\s*\(.*\)/u', '', $name))] ??= $b->id;
                    $index[self::key(str_replace('-', ' ', $name))] ??= $b->id;
                }
            }
            // Однобуквенные и общие слова из справочника («AC», «Adam», «Ram») в тексте письма — не марка.
            foreach (['ac', 'adam', 'ram', 'smart', 'seat', 'saab', 'li', 'x', 'мини'] as $noise) {
                unset($index[$noise]);
            }

            return $index;
        });
    }
}
