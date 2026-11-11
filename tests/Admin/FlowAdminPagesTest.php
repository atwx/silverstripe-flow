<?php

namespace Atwx\Flow\Tests\Admin;

use Atwx\Flow\Config\ConfigImporter;
use Atwx\Flow\Model\Workflow;
use Atwx\Flow\Tests\Stubs\TestApplication;
use Atwx\Flow\Tests\Stubs\TestCourse;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\ORM\DataObject;

/**
 * The CMS pages of FlowAdmin render (including all lists of a workflow).
 */
class FlowAdminPagesTest extends FunctionalTest
{
    protected $usesDatabase = true;

    /**
     * @var array<int, class-string<DataObject>>
     */
    protected static $extra_dataobjects = [
        TestCourse::class,
        TestApplication::class,
    ];

    private const string BASE = 'admin/flow/Atwx-Flow-Model-Workflow/EditForm/field/Atwx-Flow-Model-Workflow/item/';

    protected function setUp(): void
    {
        parent::setUp();
        ConfigImporter::create()->importFile(__DIR__ . '/../Config/config.yml');
        $this->logInWithPermission('ADMIN');
    }

    public function testListAndWorkflowPagesRender(): void
    {
        $this->assertSame(200, $this->get('admin/flow')->getStatusCode());

        $workflow = Workflow::get()->find('Code', 'course');
        $this->assertNotNull($workflow);
        $response = $this->get(self::BASE . $workflow->ID . '/edit');
        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        $this->assertStringContainsString('Title, Venue', $body, 'field group list shows its fields');
        $this->assertStringContainsString('In Prüfung', $body);
    }

    public function testChildRecordPagesRender(): void
    {
        $workflow = Workflow::get()->find('Code', 'course');
        $this->assertNotNull($workflow);
        $relations = ['Phases', 'Statuses', 'Transitions', 'Roles', 'FieldGroups', 'FieldPermissions', 'CustomFields'];
        foreach ($relations as $relation) {
            $record = $workflow->$relation()->first();
            $this->assertNotNull($record, $relation);
            $url = self::BASE . $workflow->ID . '/ItemEditForm/field/' . $relation . '/item/' . $record->ID . '/edit';
            $this->assertSame(200, $this->get($url)->getStatusCode(), $relation);

            if ($relation !== 'FieldGroups') {
                $url = self::BASE . $workflow->ID . '/ItemEditForm/field/' . $relation . '/item/new';
                $this->assertSame(200, $this->get($url)->getStatusCode(), $relation . ' (new)');
            }
        }
    }
}
