<?php

declare(strict_types=1);

namespace Koertho\ContaoChurchTools\Calendar\Backend;

use Contao\CoreBundle\Cache\CacheTagManager;
use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Service\ResetInterface;

/** Keep the series row locked through native child deletion, not just the check. */
final class SeriesDeletionProtection implements ResetInterface
{
    private bool $ownsTransaction = false;
    private array $ids = [];

    public function __construct(private readonly Connection $connection, private readonly ?CacheTagManager $cacheTags = null) {}

    public function protect(int $id): void
    {
        if (!$this->connection->isTransactionActive()) {
            $this->connection->beginTransaction();
            $this->ownsTransaction = true;
        }
        try {
            $this->connection->fetchOne('SELECT id FROM tl_ct_series_templates WHERE id=? FOR UPDATE', [$id]);
            if ($this->connection->fetchOne('SELECT id FROM tl_calendar_events WHERE ct_series_template_id=? LIMIT 1 FOR UPDATE', [$id]) !== false) {
                throw new AccessDeniedException('Series templates with linked events cannot be deleted.');
            }
            $this->ids[$id] = true;
        } catch (\Throwable $error) {
            $this->reset();
            throw $error;
        }
    }

    #[AsEventListener(event: KernelEvents::RESPONSE, priority: 1024)]
    public function response(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->ownsTransaction) {
            return;
        }
        if ($event->getResponse()->getStatusCode() >= 400) {
            $this->reset();
            return;
        }
        $this->connection->commit();
        $this->ownsTransaction = false;
        $tags = ['contao.db.tl_ct_series_templates'];
        foreach (array_keys($this->ids) as $id) {
            $tags[] = 'contao.db.tl_ct_series_templates.'.$id;
        }
        $this->ids = [];
        $this->cacheTags?->invalidateTags($tags);
    }

    #[AsEventListener(event: KernelEvents::EXCEPTION)]
    public function exception(ExceptionEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->reset();
        }
    }

    public function reset(): void
    {
        if ($this->ownsTransaction && $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        $this->ownsTransaction = false;
        $this->ids = [];
    }
}
