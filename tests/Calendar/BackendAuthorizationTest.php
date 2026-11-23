<?php

declare(strict_types=1);

namespace Koertho\ContaoChurchTools\Tests\Calendar;

use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\CoreBundle\Security\DataContainer\DeleteAction;
use Contao\CoreBundle\Security\DataContainer\UpdateAction;
use Contao\CoreBundle\Security\DataContainer\ReadAction;
use Doctrine\DBAL\DriverManager;
use Koertho\ContaoChurchTools\Calendar\Security\CalendarProtectionVoter;
use Koertho\ContaoChurchTools\Calendar\Security\SeriesContentVoter;
use Koertho\ContaoChurchTools\Calendar\Security\SeriesTemplateVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class BackendAuthorizationTest extends TestCase
{
    public function testDeletingArchiveCannotCascadeThroughImportedEvents(): void
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('CREATE TABLE tl_calendar_events (id INTEGER PRIMARY KEY,pid INTEGER,ct_sync_key TEXT)');
        $db->insert('tl_calendar_events', ['id' => 1, 'pid' => 2, 'ct_sync_key' => 'key']);
        $voter = new CalendarProtectionVoter($db);
        $attribute = [ContaoCorePermissions::DC_PREFIX.'tl_calendar'];
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(new NullToken(), new DeleteAction('tl_calendar', ['id' => 2]), $attribute));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote(new NullToken(), new DeleteAction('tl_calendar', ['id' => 3]), $attribute));
    }

    public function testReferencedSeriesCannotBeDeletedAndTitleCannotBeForged(): void
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('CREATE TABLE tl_calendar_events (id INTEGER PRIMARY KEY,ct_series_template_id INTEGER)');
        $db->insert('tl_calendar_events', ['id' => 1, 'ct_series_template_id' => 7]);
        $authorization = $this->createStub(AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->willReturn(true);
        $voter = new SeriesTemplateVoter($db, $authorization);
        $attribute = [ContaoCorePermissions::DC_PREFIX.'tl_ct_series_templates'];
        $row = ['id' => 7, 'ct_appointment_id' => 70, 'title' => 'CT title'];
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(new NullToken(), new DeleteAction('tl_ct_series_templates', $row), $attribute));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(new NullToken(), new UpdateAction('tl_ct_series_templates', $row, ['title' => 'Forged']), $attribute));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote(new NullToken(), new UpdateAction('tl_ct_series_templates', $row, ['teaser' => 'Editorial']), $attribute));
    }

    public function testNestedSeriesContentRequiresItsOwnModulePermission(): void
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('CREATE TABLE tl_ct_series_templates (id INTEGER PRIMARY KEY)');
        $db->executeStatement('CREATE TABLE tl_content (id INTEGER PRIMARY KEY,pid INTEGER,ptable TEXT)');
        $db->insert('tl_ct_series_templates', ['id' => 7]);
        $db->insert('tl_content', ['id' => 10, 'pid' => 7, 'ptable' => 'tl_ct_series_templates']);
        $authorization = $this->createStub(AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->willReturn(false);
        $voter = new SeriesContentVoter($db, $authorization);
        $attribute = [ContaoCorePermissions::DC_PREFIX.'tl_content'];
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(new NullToken(), new ReadAction('tl_content', ['id' => 11, 'pid' => 10, 'ptable' => 'tl_content']), $attribute));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(new NullToken(), new ReadAction('tl_content', ['id' => 10, 'pid' => 7, 'ptable' => 'tl_ct_series_templates']), $attribute));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote(new NullToken(), new ReadAction('tl_content', ['id' => 12, 'pid' => 1, 'ptable' => 'tl_article']), $attribute));
    }
}
