<?php

namespace Atwx\Flow\Retention;

use Atwx\SilverstripeFrontdeskKit\Controller\FrontdeskController;
use Atwx\SilverstripeFrontdeskKit\Table\ColumnCollection;
use Atwx\SilverstripeFrontdeskKit\Table\RowAction;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\Security\SecurityToken;
use SilverStripe\Security\Security;

/**
 * "Löschläufe": the retention rules with the number of due records, a
 * preview per rule, the run, and the log of past runs (the list).
 * Route it in the project:
 *
 *   SilverStripe\Control\Director:
 *     rules:
 *       'deletion-runs//$Action/$ID': 'Atwx\Flow\Retention\DeletionRunController'
 *
 * Permissions: FDK_DELETION_RUNS_VIEW (rules, preview, log) and
 * FDK_DELETION_RUNS_EDIT (run).
 */
class DeletionRunController extends FrontdeskController
{
    private static string $managed_model = DeletionRun::class;

    private static string $url_segment = 'deletion-runs';

    private static string $title = 'Löschläufe';

    private static bool $check_record_permissions = true;

    private static bool $allow_add = false;

    private static bool $allow_get_delete = false;

    private static array $allowed_actions = [
        'rule',
        'execute' => '->canEdit',
    ];

    /**
     * The rules with their due records (count only).
     *
     * @return ArrayList<ArrayData>
     */
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- template method
    public function RetentionRules(): ArrayList
    {
        $rules = ArrayList::create();
        foreach (Retention::singleton()->rules() as $rule) {
            $last = DeletionRun::get()->filter('Rule', $rule->getName())->first();
            $rules->push(ArrayData::create([
                'Name' => $rule->getName(),
                'Title' => $rule->getTitle(),
                'Description' => $rule->getDescription(),
                'Cutoff' => $rule->cutoff(),
                'Due' => $rule->candidates()->count(),
                'Automatic' => $rule->isAutomatic(),
                'LastRun' => $last,
                'Link' => $this->Link('rule/' . $rule->getName()),
            ]));
        }
        return $rules;
    }

    /**
     * Preview of a rule with the button to run it.
     */
    public function rule(HTTPRequest $request): DBHTMLText|HTTPResponse
    {
        $rule = Retention::singleton()->rule((string) $request->param('ID'));
        if (!$rule) {
            return $this->httpError(404);
        }
        $preview = Retention::singleton()->preview($rule);
        return $this->customise([
            'Title' => $rule->getTitle(),
            'Rule' => ArrayData::create([
                'Name' => $rule->getName(),
                'Description' => $rule->getDescription(),
                'Cutoff' => $rule->cutoff(),
                'Automatic' => $rule->isAutomatic(),
                'ModelName' => DataObject::singleton($rule->modelClass())->i18n_plural_name(),
            ]),
            'Due' => $preview['count'],
            'Labels' => ArrayList::create(array_map(
                fn ($label) => ArrayData::create(['Label' => $label]),
                $preview['labels']
            )),
            'More' => max(0, $preview['count'] - count($preview['labels'])),
            'RunLink' => $this->Link('execute/' . $rule->getName()),
            'BackLink' => $this->Link(),
        ])->renderWith([self::class . '_rule', 'Page']);
    }

    /**
     * Runs a rule (POST with security token; "run" is reserved by RequestHandler).
     */
    public function execute(HTTPRequest $request): HTTPResponse
    {
        $rule = Retention::singleton()->rule((string) $request->param('ID'));
        if (!$rule) {
            return $this->httpError(404);
        }
        if (!$request->isPOST() || !SecurityToken::inst()->checkRequest($request)) {
            return $this->httpError(400);
        }
        $run = Retention::singleton()->run($rule, Security::getCurrentUser());
        $this->flash(
            _t(
                self::class . '.DONE',
                '{rule}: {deleted} deleted, {kept} kept, {failed} failed.',
                [
                    'rule' => $rule->getTitle(),
                    'deleted' => $run->Deleted,
                    'kept' => $run->Kept,
                    'failed' => $run->Failed,
                ]
            ),
            $run->Failed ? 'error' : 'success'
        );
        return $this->redirect($this->Link());
    }

    protected function defineColumns(): ColumnCollection
    {
        $labels = DeletionRun::singleton()->fieldLabels();
        $columns = ColumnCollection::create();
        $names = ['Created', 'RuleTitle', 'Cutoff', 'Deleted', 'Kept', 'Failed', 'StartedBy', 'KeptReasonsText'];
        foreach ($names as $name) {
            $columns->make($name, $labels[$name] ?? $name)->end();
        }
        return $columns;
    }

    /**
     * The log is read only.
     *
     * @return RowAction[]
     */
    protected function defineRowActions(DataObject $record): array
    {
        return [];
    }
}
