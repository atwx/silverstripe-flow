<?php

namespace Atwx\Flow\Tests\Stubs;

use Atwx\Flow\Controller\FlowCaseController;
use Atwx\SilverstripeFrontdeskKit\Forms\FieldLayout;
use Atwx\SilverstripeFrontdeskKit\Table\ColumnCollection;
use Atwx\SilverstripeFrontdeskKit\Table\RecordAction;
use SilverStripe\Dev\TestOnly;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\ORM\DataObject;

/**
 * Case controller for the test course; exposes the protected hooks.
 */
class TestCourseController extends FlowCaseController implements TestOnly
{
    private static string $managed_model = TestCourse::class;

    private static string $url_segment = 'test-courses';

    public function layout(?DataObject $record): ?FieldLayout
    {
        return $this->defineFieldLayout($record);
    }

    /**
     * @return RecordAction[]
     */
    public function recordActions(DataObject $record): array
    {
        return $this->defineRecordActions($record);
    }

    /**
     * @return ArrayList<\SilverStripe\Model\ArrayData>|null
     */
    public function timeline(DataObject $record): ?ArrayList
    {
        return $this->defineTimeline($record);
    }

    /**
     * @return ArrayList<\SilverStripe\Model\ArrayData>|null
     */
    public function headerFields(DataObject $record): ?ArrayList
    {
        return $this->defineHeaderFields($record);
    }

    /**
     * @param class-string $class
     */
    public function showsSubController(string $class, DataObject $record): bool
    {
        return $this->canShowSubController($class, $record);
    }

    public function columns(): ColumnCollection
    {
        return $this->defineColumns();
    }

    /**
     * IDs of the list after applying the filters of the current request.
     *
     * @return array<int, int>
     */
    public function filteredIDs(): array
    {
        return array_map('intval', $this->getQuery()->sort('ID')->column('ID'));
    }
}
