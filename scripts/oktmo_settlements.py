#!/usr/bin/env python3
"""
Справочник населённых пунктов из ОКТМО (Росстат, раздел 2 — «населённые пункты»): все города, посёлки, сёла и деревни
с регионом и районом. Пишет database/data/settlements.csv.gz, его грузит `Cars\\Settlements\\Import` (миграция и сидер).

    python3 scripts/oktmo_settlements.py oktmo.csv

oktmo.csv — выгрузка классификатора как её отдаёт Росстат (cp1251, «;», 13 столбцов; зеркало —
github.com/prog815/oktmo). Регион — по первым двум цифрам кода, автономные округа внутри областей — по третьей
(Ненецкий 11 8…, Ханты-Мансийский 71 8…, Ямало-Ненецкий 71 9…); номера регионов — database/data/regions.csv.
Ранг — чем меньше, тем выше в подсказке: город, потом посёлки городского типа, сёла, деревни; центр района — выше
остальных своего вида. Москва, Петербург и Севастополь в разделе 2 почти пусты — их города берутся из раздела 1.
"""
import csv
import gzip
import re
import sys

BASE = {'г': 1, 'пгт': 2, 'рп': 2, 'гп': 2, 'кп': 2, 'дп': 2,
        'с': 3, 'ст-ца': 3, 'сл': 3, 'аул': 3, 'п': 3, 'нп': 3, 'ст': 3, 'аал': 3,
        'д': 4, 'х': 4, 'м': 4, 'у': 4, 'починок': 4, 'выселок': 4, 'п.ст': 4, 'рзд': 4, 'заимка': 4, 'арбан': 4,
        'ж/д ст': 4, 'ж/д рзд': 4, 'ж/д пл': 4, 'ж/д оп': 4, 'ж/д будка': 4, 'ж/д казарм': 4, 'ж/д платф': 4}
TYPE = re.compile(r'^(ж/д\s+\S+|п\.ст|ст-ца|\S+)\s+(.+)$')


def region_of(r):
    if r[0] == '11' and r[1].startswith('8'):
        return '11800'
    if r[0] == '71' and r[1].startswith('8'):
        return '71800'
    if r[0] == '71' and r[1].startswith('9'):
        return '71900'
    return r[0]


def main(path):
    rows = list(csv.reader(open(path, encoding='cp1251'), delimiter=';'))
    districts = {(r[0], r[1]): r[6].rstrip('/').strip() for r in rows
                 if r[5] == '1' and r[1] != '000' and r[2] == '000' and r[3] == '000' and not r[1].endswith('00')}
    centers = {(r[0], r[1], r[7].strip()) for r in rows if r[5] == '1' and r[7].strip()}
    out, seen = [], set()

    def add(oktmo, region, kind, name, district, center):
        key = (region, district, kind, name)
        if key in seen:
            return
        seen.add(key)
        out.append([oktmo, region, kind, name, district, BASE[kind] * 2 - (1 if center else 0)])

    for r in rows:
        if r[5] != '2' or r[3] == '000':
            continue
        m = TYPE.match(r[6].strip())
        if not m or m.group(1) not in BASE:
            continue
        kind, name = m.group(1), re.sub(r'\s+', ' ', m.group(2)).strip()
        add(''.join(r[:4]), region_of(r), kind, name, districts.get((r[0], r[1]), ''), (r[0], r[1], r[6].strip()) in centers)

    # Города федерального значения и их города (Колпино, Кронштадт, Троицк, Инкерман) — из раздела 1.
    for code, city in (('45', 'Москва'), ('40', 'Санкт-Петербург'), ('67', 'Севастополь')):
        add(code + '000000000', code, 'г', city, '', True)
    for r in rows:
        if r[0] in ('40', '45', '67') and r[5] == '1':
            m = re.match(r'^(?:город|городской округ)\s+(.+?)/?$', r[6].strip())
            if m:
                add(''.join(r[:4]), r[0], 'г', m.group(1), '', False)
    for city in ('Зеленоград', 'Щербинка'):
        add('45000000000', '45', 'г', city, '', False)

    out.sort(key=lambda x: (x[1], x[5], x[3]))
    with gzip.open('database/data/settlements.csv.gz', 'wt', encoding='utf-8', compresslevel=9) as f:
        w = csv.writer(f, delimiter=';', lineterminator='\n')
        w.writerow(['oktmo', 'region', 'type', 'name', 'district', 'rank'])
        w.writerows(out)
    print(len(out), 'населённых пунктов')


if __name__ == '__main__':
    main(sys.argv[1])
