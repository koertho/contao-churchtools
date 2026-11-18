<?php

declare(strict_types=1);

namespace Koertho\ContaoChurchTools\Calendar\Backend;

use Contao\CoreBundle\DataContainer\ClipboardManager;
use Doctrine\DBAL\ArrayParameterType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class BackendActionGuard
{
    public function __construct(private readonly Connection $connection, private readonly RequestStack $requests, #[Autowire(service: 'contao.data_container.clipboard_manager')] private readonly ClipboardManager $clipboard) {}

    public function guard(string $table, int $id): void
    {
        $request = $this->requests->getCurrentRequest();
        $action = $request?->query->get('act');
        if (!in_array($action, ['copy', 'copyAll', 'cut', 'cutAll', 'delete', 'deleteAll'], true)) {
            return;
        }
        $ids = $id > 0 ? [$id] : [];
        if (in_array($action, ['copyAll', 'cutAll'], true)) {
            $ids = array_values(array_filter(array_map('intval', $this->clipboard->getIds($table)), static fn (int $id): bool => $id > 0));
        } elseif ($action === 'deleteAll') {
            $selected = $request->request->all()['IDS'] ?? [];
            if ($request->hasSession()) {
                $session = $request->getSession()->getBag('contao_backend')->all();
                $selected = $selected ?: ($session['CURRENT']['IDS'] ?? []);
            }
            $ids = array_values(array_filter(array_map('intval', is_array($selected) ? $selected : []), static fn (int $id): bool => $id > 0));
        }
        if ($ids === []) {
            return;
        }
        $column = $table === 'tl_calendar' ? 'pid' : 'id';
        if ($this->connection->fetchOne('SELECT id FROM tl_calendar_events WHERE '.$column.' IN (?) AND ct_sync_key IS NOT NULL LIMIT 1', [$ids], [ArrayParameterType::INTEGER]) !== false) {
            throw new AccessDeniedException('Imported ChurchTools events cannot be copied, moved or deleted.');
        }
    }
}
