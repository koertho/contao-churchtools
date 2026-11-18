<?php

declare(strict_types=1);

namespace Koertho\ContaoChurchTools\Tests\Calendar;

use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\CoreBundle\Security\DataContainer\CreateAction;
use Contao\CoreBundle\Security\DataContainer\DeleteAction;
use Contao\CoreBundle\Security\DataContainer\UpdateAction;
use Doctrine\DBAL\Connection;
use Koertho\ContaoChurchTools\Calendar\Security\ImportedEventVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class BackendProtectionTest extends TestCase
{
    public function testImportedDeletionAndForgedSourceUpdatesAreDenied(): void
    {
        $voter = new ImportedEventVoter();
        $row = ['id' => 1, 'pid' => 2, 'ct_sync_key' => 'key', 'title' => 'CT title', 'teaser' => 'Editorial'];
        $attribute = [ContaoCorePermissions::DC_PREFIX.'tl_calendar_events'];
        $token = new NullToken();
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, new DeleteAction('tl_calendar_events', $row), $attribute));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, new UpdateAction('tl_calendar_events', $row, ['title' => 'Forged']), $attribute));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, new UpdateAction('tl_calendar_events', $row, ['pid' => 3]), $attribute));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, new UpdateAction('tl_calendar_events', $row, ['teaser' => 'New text', 'published' => '']), $attribute));
    }

    public function testManualEventsRemainEditableAndBackendCannotForgeSyncReferences(): void
    {
        $voter = new ImportedEventVoter();
        $row = ['id' => 1, 'pid' => 2, 'ct_sync_key' => null, 'title' => 'Manual'];
        $attribute = [ContaoCorePermissions::DC_PREFIX.'tl_calendar_events'];
        $token = new NullToken();
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, new DeleteAction('tl_calendar_events', $row), $attribute));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, new UpdateAction('tl_calendar_events', $row, ['title' => 'Edited']), $attribute));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, new CreateAction('tl_calendar_events', ['ct_sync_key' => 'forged']), $attribute));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, new UpdateAction('tl_calendar_events', $row, ['ct_sync_key' => 'forged']), $attribute));
    }
}
