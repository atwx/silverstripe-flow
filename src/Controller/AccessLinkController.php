<?php

namespace Atwx\Flow\Controller;

use Atwx\Flow\Actor\AccessLinkActor;
use Atwx\Flow\Model\AccessLink;
use Atwx\Flow\Model\LinkAccess;
use Atwx\Flow\Ui\FlowFieldLayout;
use Atwx\Flow\Ui\FlowRecordActions;
use Atwx\SilverstripeFrontdeskKit\Controller\StandaloneRecordController;
use Atwx\SilverstripeFrontdeskKit\Forms\FieldLayout;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;

/**
 * Public page of an edit link: /flow/link/<token>.
 *
 * Shows the linked case file with the fields of the link's role, saves only
 * writable fields and offers only the role's transitions. Unknown, revoked,
 * expired links and links of closed case files answer 404. Opening the page
 * and saving are recorded for the timeline.
 */
class AccessLinkController extends StandaloneRecordController
{
    /**
     * Opening the page again within this time is not recorded again.
     */
    private static int $view_log_interval = 1800;

    private ?AccessLink $link = null;

    protected function resolveRecord(HTTPRequest $request): ?DataObject
    {
        $link = AccessLink::findByToken((string) $request->param('Key'));
        if (!$link || !$link->isUsable()) {
            return null;
        }
        $this->link = $link;
        return $link->Case();
    }

    protected function baseLink(): string
    {
        return AccessLink::URL_SEGMENT . '/' . $this->getRequest()->param('Key');
    }

    private function actor(): AccessLinkActor
    {
        $this->currentRecord();
        /** @var AccessLink $link */
        $link = $this->link;
        return new AccessLinkActor($link);
    }

    protected function defineFieldLayout(DataObject $record): FieldLayout
    {
        return FlowFieldLayout::build($record, $this->actor())
            ?? FieldLayout::create()->setDefaultLevel(FieldLayout::HIDDEN);
    }

    protected function defineRecordActions(DataObject $record): array
    {
        return FlowRecordActions::forActor($record, $this->actor());
    }

    protected function introText(DataObject $record): string
    {
        $link = $this->actor()->getLink();
        $role = $link->Role();
        return _t(
            self::class . '.INTRO',
            'Hello {name}, you are working on this record as {role}.',
            ['name' => $link->Name, 'role' => $role->exists() ? $role->Title : '']
        );
    }

    protected function onView(DataObject $record): void
    {
        $link = $this->actor()->getLink();
        $now = DBDatetime::now();
        $interval = (int) static::config()->get('view_log_interval');
        $recent = $link->LastUsedAt && strtotime((string) $link->LastUsedAt) > $now->getTimestamp() - $interval;
        if (!$recent) {
            LinkAccess::create(['LinkID' => $link->ID, 'Kind' => LinkAccess::VIEW])->write();
        }
        $link->LastUsedAt = $now->Rfc2822();
        $link->write();
    }

    /**
     * @param array<int, string> $changed
     */
    protected function afterSave(DataObject $record, array $changed): void
    {
        $labels = $record->fieldLabels(false);
        LinkAccess::create([
            'LinkID' => $this->actor()->getLink()->ID,
            'Kind' => LinkAccess::SAVE,
            'Fields' => implode(', ', array_map(fn ($name) => $labels[$name] ?? $name, $changed)),
        ])->write();
    }
}
