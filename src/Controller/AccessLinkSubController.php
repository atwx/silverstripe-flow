<?php

namespace Atwx\Flow\Controller;

use Atwx\Flow\Model\AccessLink;
use Atwx\Flow\Model\FlowRole;
use Atwx\Flow\Model\Workflow;
use Atwx\SilverstripeFrontdeskKit\Controller\FrontdeskSubController;
use Atwx\SilverstripeFrontdeskKit\Table\ColumnCollection;
use SilverStripe\Control\Email\Email;
use SilverStripe\Forms\DateField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\EmailField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;

/**
 * Edit links of a case file (sub-list of FlowCaseController): list and create.
 * After creating, the link is shown once and, with an email address, sent to
 * the person. Links are revoked with a record action of the case file.
 */
class AccessLinkSubController extends FrontdeskSubController
{
    private static string $managed_model = AccessLink::class;

    private static string $url_segment = 'links';

    private static string $title = 'Bearbeitungslinks';

    /**
     * @return DataList<AccessLink>
     */
    protected function getBaseQuery(): DataList
    {
        // PHPStan reports static<T> from DataList::filter() as incompatible with DataList<T>.
        // @phpstan-ignore return.type
        return AccessLink::get()->filter([
            'CaseClass' => $this->parentController?->getManagedModel(),
            'CaseID' => $this->getParentID(),
        ]);
    }

    /**
     * Only members who may edit the case files see and create links.
     *
     * @param mixed $member
     */
    public function canView($member = null): bool
    {
        return $this->parentController->canEdit($member);
    }

    protected function defineRowActions(DataObject $record): array
    {
        return [];
    }

    /**
     * Links have no detail page.
     */
    protected function defineColumns(): ColumnCollection
    {
        return ColumnCollection::fromSummaryFields(AccessLink::class);
    }

    protected function formFields(FieldList $fields): FieldList
    {
        $workflow = Workflow::forClass((string) $this->parentController?->getManagedModel());
        $roles = FlowRole::get()->filter(['WorkflowID' => (int) $workflow?->ID, 'IsExternal' => true]);
        $link = AccessLink::singleton();
        return FieldList::create(
            TextField::create('Name', $link->fieldLabel('Name')),
            EmailField::create('Email', $link->fieldLabel('Email'))
                ->setDescription(_t(
                    self::class . '.EMAIL_DESCRIPTION',
                    'Optional: the link is sent to this address.'
                )),
            DropdownField::create('RoleID', $link->fieldLabel('Role'), $roles->map('ID', 'Title'))
                ->setHasEmptyDefault($roles->count() !== 1),
            DateField::create('ExpiresAt', $link->fieldLabel('ExpiresAt'))
                ->setDescription(_t(
                    self::class . '.EXPIRES_DESCRIPTION',
                    'Optional. The link always ends when the case is closed.'
                ))
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function beforeWrite(DataObject $item, array $data): void
    {
        $item->setField('CaseClass', $this->parentController?->getManagedModel());
        $item->setField('CaseID', $this->getParentID());
        parent::beforeWrite($item, $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function afterWrite(DataObject $item, array $data): void
    {
        parent::afterWrite($item, $data);
        /** @var AccessLink $item */
        $token = $item->getPlainToken();
        if (!$token) {
            return;
        }
        $url = AccessLink::urlForToken($token);
        $sent = $item->Email ? $this->sendLink($item, $url) : false;
        $this->flash(
            _t(self::class . '.CREATED', 'Edit link for {name}: {url}', ['name' => $item->Name, 'url' => $url])
            . ($sent ? ' ' . _t(self::class . '.SENT', '(sent to {email})', ['email' => $item->Email]) : ''),
            'info'
        );
    }

    private function sendLink(AccessLink $link, string $url): bool
    {
        $case = $link->Case();
        $title = htmlspecialchars((string) $case->getTitle());
        $hello = _t(self::class . '.MAIL_HELLO', 'Hello {name},', ['name' => $link->Name]);
        $body = '<p>' . htmlspecialchars($hello) . '</p>'
            . '<p>' . htmlspecialchars(_t(
                self::class . '.MAIL_TEXT',
                'you can view and edit "{title}" with this link (no login needed):',
                ['title' => $case->getTitle()]
            )) . '</p>'
            . '<p><a href="' . htmlspecialchars($url) . '">' . htmlspecialchars($url) . '</a></p>';
        try {
            Email::create()
                ->setTo((string) $link->Email)
                ->setSubject(_t(self::class . '.MAIL_SUBJECT', 'Edit link: {title}', ['title' => $title]))
                ->setBody($body)
                ->send();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
