<?php

namespace App\Live\Console;

use App\Support\Surface;
use App\Users\Role;
use App\Users\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

/**
 * Сторож списков (27.10.2026, владелец: «строки превращаются в другие»): каждый список из реестра рисуется как страница и
 * как ответ живого перечитывания (`X-Live-Rows`) и сверяется — у строки столько ячеек, сколько столбцов в шапке, заголовок
 * группы на всю ширину, и строка ответа та же и под той же группой, что на странице. Только GET: можно гонять на проде.
 */
final class ListsCheck extends Command
{
    protected $signature = 'lists:check {--only= : часть адреса — только эти списки} {--user= : id того, кто смотрит (по умолчанию — свой у списка)}';

    protected $description = 'Сверяет строки списков: страница против X-Live-Rows, ячейки против шапки';

    /**
     * Реестр: [поверхность, адрес, кто смотрит — роль; у менеджера — тот, у кого больше сделок]. Новый список — сюда.
     * `{purchase}` — номер последней закупки.
     *
     * @return list<array{0: Surface, 1: string, 2: Role}>
     */
    private function lists(): array
    {
        $crm = fn (string $path) => [Surface::Crm, $path, Role::Admin];
        $offers = ['nofloor', 'unpriced', 'priced', 'slots', 'published', 'bids', 'done', 'all', 'archive', 'legacy', 'auction'];

        return [
            ...array_map(fn ($p) => $crm('/work/deals?preset='.$p), ['us', 'manager', 'supplier', 'pay', 'all', 'done']),
            $crm('/work/deals?preset=all&vid=wide'),
            ...array_map(fn ($p) => $crm('/?preset='.$p.'&vid=table'), $offers),
            $crm('/gallery?preset=live&vid=table'), $crm('/gallery?preset=interest&vid=table'),
            $crm('/alarms'),
            $crm('/settings/deliveries'), $crm('/settings/deliveries?view=people'),
            ...array_map(fn ($p) => $crm('/work/garage?preset='.$p.'&vid=table'), ['work', 'sold', 'settled', 'all']),
            ...array_map(fn ($t) => $crm('/work/money?tab='.$t), ['links', 'owed', 'payouts']),
            $crm('/purchases/{purchase}?vid=table'),
            [Surface::Park, '/requests', Role::ParkAdmin],
            [Surface::Park, '/cars?preset=stored', Role::ParkAdmin],
            [Surface::Park, '/cars?preset=out', Role::ParkAdmin],
            [Surface::Park, '/money', Role::ParkAdmin],
            ...array_map(fn ($p) => [Surface::Logistics, '/transports?preset='.$p, Role::Admin], ['new', 'logist', 'work', 'moving', 'done', 'all']),
            [Surface::Logistics, '/documents', Role::Admin],
            [Surface::Site, '/offers?vid=table', Role::Manager],
            [Surface::Site, '/gallery?vid=table', Role::Manager],
            [Surface::Site, '/account/favorites?vid=table', Role::Manager],
            [Surface::Site, '/garage?vid=table', Role::Manager],
            [Surface::Site, '/purchases/{purchase}?vid=table', Role::Manager],
            [Surface::Site, '/offers?vid=table', Role::Buyer],
        ];
    }

    /** Кто смотрит список: менеджер — с большим числом сделок (у него есть что показать), остальные — первый по роли. */
    private function viewer(Role $role): ?User
    {
        return $role === Role::Manager
            ? User::withRole($role)->orderByRaw('(select count(*) from deals where deals.buyer_id = users.id) desc')->first()
            : User::withRole($role)->orderBy('id')->first();
    }

    public function handle(Kernel $kernel): int
    {
        $failures = 0;
        $checked = 0;
        foreach ($this->lists() as [$surface, $path, $role]) {
            if (($only = $this->option('only')) && ! str_contains($path, $only)) {
                continue;
            }
            $user = $this->option('user') ? User::findOrFail($this->option('user')) : $this->viewer($role);
            $path = str_replace('{purchase}', (string) \App\Purchases\Purchase::latest('id')->value('number'), $path);
            $page = $this->fetch($kernel, $surface, $path, $user);
            if ($page === null) {
                $this->error("{$path}: страница не открылась");
                $failures++;

                continue;
            }
            $rows = $this->rows($page);
            $problems = $this->shape($page);
            $keys = array_slice(array_keys($rows), 0, 40);
            if ($keys) {
                $live = $this->fetch($kernel, $surface, $path, $user, ['X-Live-Rows' => implode(',', $keys)]);
                $liveRows = $live ? $this->rows($live) : [];
                foreach ($keys as $key) {
                    $theirs = $liveRows[$key] ?? null;
                    if (! $theirs) {
                        $problems[] = "строка {$key} пропала в ответе X-Live-Rows";
                    } elseif ($theirs['html'] !== $rows[$key]['html']) {
                        $problems[] = "строка {$key} в ответе X-Live-Rows другая";
                    } elseif ($theirs['group'] !== $rows[$key]['group']) {
                        $problems[] = "строка {$key} в ответе под группой «{$theirs['group']}», на странице — «{$rows[$key]['group']}»";
                    }
                }
            }
            $checked++;
            $label = "{$surface->value} {$path} — {$user?->name}, строк ".count($rows);
            if ($problems) {
                $failures++;
                $this->error($label);
                foreach (array_slice(array_unique($problems), 0, 10) as $p) {
                    $this->line('  · '.$p);
                }
            } else {
                $this->info($label.': ок');
            }
        }
        $this->line("Списков: {$checked}, с ошибками: {$failures}");

        return $failures ? self::FAILURE : self::SUCCESS;
    }

    private function fetch(Kernel $kernel, Surface $surface, string $path, ?User $user, array $headers = []): ?DOMXPath
    {
        $request = Request::create($surface->url($path), 'GET', server: collect($headers)->mapWithKeys(fn ($v, $k) => ['HTTP_'.strtoupper(str_replace('-', '_', $k)) => $v])->all());
        auth()->setUser($user);
        $request->setUserResolver(fn () => $user);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
        app()->forgetInstance('request');
        if ($response->getStatusCode() !== 200) {
            return null;
        }
        $doc = new DOMDocument;
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>'.$response->getContent());
        libxml_clear_errors();

        return new DOMXPath($doc);
    }

    /** Строки списка по ключу (вне карточки и шторок): разметка без пробелов и группа над ней. @return array<string, array{html: string, group: string}> */
    private function rows(DOMXPath $x): array
    {
        $out = [];
        foreach ($x->query('//*[@data-detail-key][not(ancestor::turbo-frame[@id="detail"])][not(ancestor::dialog)]') as $el) {
            /** @var DOMElement $el */
            $out[$el->getAttribute('data-detail-key')] = ['html' => $this->normal($el->ownerDocument->saveHTML($el)), 'group' => $this->groupOf($el)];
        }

        return $out;
    }

    private function groupOf(DOMElement $el): string
    {
        for ($n = $el->previousSibling; $n; $n = $n->previousSibling) {
            if ($n instanceof DOMElement && str_contains(' '.$n->getAttribute('class').' ', ' table-group ')) {
                return $n->getAttribute('data-group') ?: trim(preg_replace('/\d+/', '', $n->textContent));
            }
        }

        return '';
    }

    /** Время и токены меняются от запроса к запросу — не в счёт. */
    private function normal(string $html): string
    {
        return preg_replace(['/\s+/', '/name="_token" value="[^"]*"/', '/data-timer-[a-z-]+-value="[^"]*"/'], [' ', '', ''], $html);
    }

    /** Ячейки строк и ширина заголовков групп против шапки каждой таблицы. @return list<string> */
    private function shape(DOMXPath $x): array
    {
        $problems = [];
        foreach ($x->query('//table[contains(concat(" ", @class, " "), " table ")]') as $table) {
            $head = $x->query('./thead/tr[1]/th', $table)->length;
            if (! $head) {
                continue;
            }
            foreach ($x->query('./tbody/tr', $table) as $tr) {
                /** @var DOMElement $tr */
                if (str_contains(' '.$tr->getAttribute('class').' ', ' table-group ')) {
                    $span = (int) ($x->query('./th', $tr)->item(0)?->getAttribute('colspan') ?: 1);
                    if ($span !== $head) {
                        $problems[] = "заголовок группы «".trim($tr->textContent)."» шириной {$span}, столбцов {$head}";
                    }

                    continue;
                }
                $cells = 0;
                foreach ($x->query('./td', $tr) as $td) {
                    $cells += (int) ($td->getAttribute('colspan') ?: 1);
                }
                if ($cells !== $head && $tr->hasAttribute('data-detail-key')) {
                    $problems[] = "строка {$tr->getAttribute('data-detail-key')}: ячеек {$cells}, столбцов {$head}";
                }
            }
        }

        return $problems;
    }
}
