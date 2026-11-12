# silverstripe-flow

Domain-neutral case files ("Fallakte") for Silverstripe 6, built on
[`atwx/silverstripe-frontdesk-kit`](https://github.com/atwx/silverstripe-frontdesk-kit).

An extension turns any domain class into a case file with:

- phases and statuses with allowed transitions per role and a status history
- field permissions per field group × role × status (hidden / read / write)
- revocable edit links for external collaborators without an account (random token, stored as hash)
- custom fields stored as JSON, usable in forms, lists, filters and exports
- a timeline of notes, status changes and link accesses
- configuration as database records, importable/exportable as YAML

## Requirements

- PHP 8.4
- Silverstripe framework 6
- `atwx/silverstripe-frontdesk-kit` (dev-main during the MVP)

## Quick start

The module is not on Packagist yet; add the repository first:

```sh
composer config repositories.flow vcs https://github.com/atwx/silverstripe-flow.git
composer require atwx/silverstripe-flow:dev-main
```

1. Make a domain class a case file and group its fields:

   ```php
   class Event extends DataObject
   {
       private static array $extensions = [CaseFileExtension::class];

       private static array $flow_field_groups = [
           'basics' => ['title' => 'Eckdaten', 'fields' => ['Title', 'StartDate']],
           'announcement' => ['title' => 'Ausschreibung', 'fields' => ['Description']],
       ];
   }
   ```

2. Configure the workflow in the CMS (*Abläufe*) or import a YAML file
   (`sake flow:import-config app/_flow/config.yml`, see below).
3. Add a frontdesk controller for the list, form and record view:

   ```php
   class EventController extends FlowCaseController
   {
       private static string $managed_model = Event::class;
       private static string $url_segment = 'events';
       private static string $title = 'Veranstaltungen';
   }
   ```

   ```yaml
   SilverStripe\Control\Director:
     rules:
       'events//$Action/$ID/$OtherID': App\Controllers\EventController
   ```

4. `sake db:build --flush`; give the groups the frontdesk permissions
   (`FDK_EVENTS_VIEW`, `FDK_EVENTS_EDIT`) and the configuring members `FLOW_CONFIGURE`.

The demo project [`atwx/silverstripe-flow-demo`](https://github.com/atwx/silverstripe-flow-demo)
shows a complete setup, including end-to-end tests.

## Case files

Any data class becomes a case file through the extension:

```yaml
App\Models\Event:
  extensions:
    - Atwx\Flow\Extension\CaseFileExtension
```

It adds the status (`FlowStatus`) and the status history (`FlowStatusChanges`).
New records start in the initial status of the workflow for their class. The status
only changes through the `TransitionService`; writing `FlowStatusID` directly is
ignored.

```php
$event->availableTransitions();               // for the current member
$event->transitionTo('in_review', null, 'Comment');
```

Rights come from roles: a logged-in member has every internal role whose group
they are in. Hooks on the domain class (or its extensions) and on the service:

- `onBeforeTransition(Transition $transition, FlowActor $actor, string $comment)`,
  inside the database transaction; throw a `TransitionException` to refuse
- `onAfterTransition(StatusChange $change, FlowActor $actor)`, after commit

Extensions of `TransitionService` receive the case file as additional first argument.

> Status: under construction (MVP). The demo project is
> [`atwx/silverstripe-flow-demo`](https://github.com/atwx/silverstripe-flow-demo).

## Field groups and permissions

The domain class groups its fields; permissions are granted per group, role and
status. Use the form field names (e.g. `VenueID` for a has_one).

```php
private static array $flow_field_groups = [
    'basics' => ['title' => 'Eckdaten', 'fields' => ['Title', 'Date', 'VenueID']],
    'announcement' => ['title' => 'Ausschreibung', 'fields' => ['Description']],
];
```

The groups are created per workflow (on save and on `dev/build`); title and order
are maintained in the CMS. A permission entry sets the level (`hidden`, `read`,
`write`) of a role for a group, in one status or in all statuses:

- an entry for the current status beats an entry for all statuses,
- without an entry the group is hidden, fields in no group are hidden,
- an actor with several roles gets the highest level.

`FieldAccess` is the only place that applies these rules: `fieldLevels()`,
`writableFields()`, `readableFields()` and `filterWritable()`, which drops every
submitted value the actor may not write.

In the configuration file:

```yaml
    field_groups:
      - code: announcement
        title: Ausschreibung
    permissions:
      - group: announcement
        role: speaker
        level: read
      - group: announcement
        role: speaker
        status: with_speaker      # omit for all statuses
        level: write
```

## Configuration

Workflows are maintained in the CMS under *Abläufe* (`FlowAdmin`), which needs the
permission `FLOW_CONFIGURE`. A workflow belongs to one domain class and holds
phases, statuses, transitions (with the roles allowed to perform them) and roles
(internal roles map to a security group, external roles act through edit links).
Every record has a stable `Code` for configuration files and hooks.

### Import and export

The configuration can be imported from and exported to YAML, e.g. as start
configuration of an installation, for tests, or to move a workflow from a test
system to live:

```sh
vendor/bin/sake flow:import-config app/_flow/config.yml
vendor/bin/sake flow:export-config [file] [--workflow=code]
```

The import matches records by code, creates or updates them and deletes nothing.
Groups of internal roles are matched by their code and created if missing. An
invalid entry aborts the whole import. Lists define the order (`Sort`).

```yaml
workflows:
  - code: event
    title: Veranstaltung
    class: App\Models\Event
    initial_status: draft
    roles:
      - code: organisers
        title: Organisation
        group: organisers          # security group code
      - code: speaker
        title: 'Referent:in'
        external: true             # acts through edit links only
    phases:
      - code: planning
        title: Planung
    statuses:
      - code: draft
        title: Entwurf
        phase: planning
      - code: done
        title: Abgeschlossen
        phase: planning
        final: true
    transitions:
      - from: draft
        to: done
        title: Abschließen         # optional label of the action
        roles: [organisers]
```

The export writes the same canonical format, so an exported file can be imported
again unchanged.

## Frontdesk controller

`FlowCaseController` is a frontdesk-kit controller for case files. It arranges the
form and the record view by field group with the member's access levels (changes
to fields that are not writable are reset on save), adds status and phase as
columns and filters (cells of fields the member may not read stay empty, also in
the export), offers "Change status" with the member's transitions, and shows the
status history as timeline.

```php
class EventController extends FlowCaseController
{
    private static string $managed_model = Event::class;
    private static string $url_segment = 'events';
    private static string $title = 'Veranstaltungen';
}
```

Extensions can add entries to the timeline with `updateFlowTimeline(ArrayList $entries, DataObject $record)`.

## Responsibilities

A workflow can name responsibilities ("Zuständigkeit"), e.g. *Verantwortlich*
or *Wo ist die Akte?*: one member per case file, chosen from the members of an
internal role. Maintained in the CMS (workflow → *Responsibilities*) or in the
configuration file:

```yaml
    responsibilities:
      - code: responsible
        title: Verantwortlich
        role: advisers          # its members can be assigned
```

`FlowCaseController` adds

- the record action *Responsibility* (one select per responsibility) for members
  who may edit and have a role in the workflow,
- a list column and a filter per responsibility (`Assignee_<code>`),
- the list view *My case files* (`?view=mine`),
- the assignees in the header of record pages and every change in the timeline.

```php
AssignmentService::singleton()->assign($case, $responsibility, $member, $by);
$case->flowAssignee('responsible');   // ?Member
```

Only candidates can be assigned; every change is stored (`AssignmentChange`).
Assignments and their history are deleted with the case file.

## Documents

Templates ("Vorlagen") of a workflow turn into PDF documents of a case file:
title (with placeholders) and an HTML body with placeholders, maintained in the
CMS (workflow → *Templates*) or in the configuration file:

```yaml
    documents:
      - code: travel_letter
        title: Reisebrief
        document_title: 'Reisebrief {{Child.FirstName}}'
        body: '<p>Liebe Familie {{Child.Family.Surname}}, …</p>'
```

Placeholders: `{{Field}}`, `{{Relation.Field}}` (has_one chains, dates as
dd.mm.yyyy, getters like `{{Child.Title}}`; no other methods), `{{Flow.<code>}}`
(custom field), `{{Assignee.<code>}}` (responsibility), `{{Status}}`, `{{Today}}`,
`{{Me.Name}}`. Values are HTML-escaped; unknown placeholders stay empty.

`FlowCaseController` adds the record action *Create document* (members who may
edit and have a role in the workflow), the sub-list *Documents* with download
(`<segment>/documents/<ID>/download/<document>`, only for members who may view
the case file) and an entry in the timeline. The PDF is stored protected
(`DBFile`, not in the asset admin) and deleted with the case file.

The body is rendered into the template `Atwx\Flow\Document\FlowDocument` —
override it in the project for the letterhead. PDFs are made by the
`PdfRenderer` service, by default with `atwx/silverstripe-headless-pdf`
(headless Chrome; the HTML is printed from a temporary file, nothing is served
through a URL).

## Follow-ups

Follow-ups ("Wiedervorlagen") have a due date, a text and the member who takes
care of them. They are added in the case file (sub-list *Wiedervorlagen*, also
marked as done there) or created by rules when a case file enters a status:

```yaml
    follow_ups:
      - code: chase_schools
        title: Schulen nachfassen
        status: profile_sent         # entering this status creates the follow-up
        days: 14                     # due after 14 days
        responsibility: responsible  # for the member responsible (optional)
        close_on_leave: true         # default: closed when the status is left
```

`FollowUpController` lists the open follow-ups of all case files whose list the
member may view (views *Meine fälligen*, *Meine*, *Überfällig*; bulk action
*Als erledigt markieren*); route it in the project:

```yaml
SilverStripe\Control\Director:
  rules:
    'followups//$Action/$ID/$OtherID': Atwx\Flow\Controller\FollowUpController
```

Creating and closing appear in the timeline; follow-ups are deleted with the case file.

## Checklists

A workflow can have a checklist ("Unterlagen und Aufgaben"): every case file
gets one entry per item with state (*offen*, *angefordert*, *erledigt*,
*nicht nötig*), date and comment, edited in the sub-list *Checkliste* (entries
are created when the list is shown; they cannot be added or deleted there).
An item can be required before a status: the case file cannot enter it until
the item is done or not needed (the status change is refused with the list of
missing items).

An item can take a file (`file: optional` or `file: required`): the entry form
gets an upload field (frontdesk-kit `FileUploadField`), the list links the file.
With `required` the entry can only be marked *erledigt* with a file (*nicht
nötig* needs none). Files are stored protected and downloaded through the
checklist of the case file (`checklist/<case ID>/file/<entry ID>/FileID`), so
only members who may see the case file get them; they are deleted with the
entry and the case file.

```yaml
    checklist:
      - code: health_form
        title: Health Form
        required_before: placed
        file: required
```

## Edit links

People without an account work on a case file through an edit link. Members who
may edit create links in the case file (sub-list "Edit links": name, optional
email, external role, optional expiry date); the link is shown once and, with an
email address, sent to the person. Only a SHA-256 hash of the 32-byte token is
stored.

The link page `/flow/link/<token>` (`AccessLinkController`, built on the kit's
`StandaloneRecordController`) shows the fields of the link's role, saves only
writable fields and offers only the role's transitions. A link works until it is
revoked ("Revoke edit link" in the case file), its expiry date has passed or the
case file reaches a final status; otherwise the page answers 404. Opening the
page, saving, status changes and notes via a link appear in the timeline.

Projects override the main template `FrontdeskStandalonePage` to load their own
styles.

## Custom fields

Customers add fields without a deployment: in the CMS (workflow → *Custom fields*)
with a code (fixed once created), title, type (text, multi-line text, date, yes/no,
choice), field group and options. Values are stored as JSON on the case file
(`FlowData`, keyed by code); in forms the fields are named `Flow_<code>`.

A custom field has the permissions of its field group and appears in the form, the
record view and the edit link page. Fields marked *Column in list* are list
columns and exported; choice and yes/no fields can be filtered (`JSON_VALUE`).

```php
$event->flowValue('audience');            // stored value
$event->setFlowValue('audience', 'all');  // written on the next write()
```

Values of deleted fields stay in the JSON until `sake flow:cleanup-data`
(`--dry-run` to report only) removes them. When logic depends on a value, it
should become a real field.

In the configuration file:

```yaml
    custom_fields:
      - code: audience
        title: Zielgruppe
        type: select              # text, textarea, date, checkbox, select
        group: announcement
        options: { beginners: Einsteiger, advanced: Fortgeschrittene }
        list: true                # column in the list and the export
```

## Extension points

| Hook | Where | Use |
|---|---|---|
| `onBeforeTransition(Transition, FlowActor, string $comment)` | domain class / its extensions; `TransitionService` extensions (case file first) | refuse a status change by throwing `TransitionException` (inside the transaction) |
| `onAfterTransition(StatusChange, FlowActor)` | same | notifications etc. after commit |
| `onAfterFlowNote(Note, FlowActor)` | domain class / its extensions | react to new notes |
| `updateFlowTimeline(ArrayList $entries, DataObject $record)` | `FlowCaseController` extensions | add timeline entries |
| `$flow_field_groups` | domain class config | fields per group |

Actors are `FlowActor`s: `MemberActor` (logged-in member, roles from groups) and
`AccessLinkActor` (edit link, the link's external role).

## Development

The module is developed inside the demo project
[`atwx/silverstripe-flow-demo`](https://github.com/atwx/silverstripe-flow-demo):
there it is installed from source, so `vendor/atwx/silverstripe-flow` is a git clone
of this repository (see the demo README).

```sh
cd silverstripe-flow-demo
ddev flow-test                      # all module tests
ddev flow-test --filter Workflow    # PHPUnit arguments are passed through
ddev flow-check                     # PHPCS and PHPStan
```

`ddev flow-test` runs PHPUnit with the root DB user (SapphireTest creates temporary
databases) and a fresh manifest.

### Standalone (as in CI)

CI installs the module on its own, with `atwx/silverstripe-frontdesk-kit` checked out
next to it as a Composer `path` repository (`../silverstripe-frontdesk-kit`), and runs:

```sh
composer install
composer lint      # PHPCS (PSR-12)
composer phpstan   # PHPStan + Silverstan
composer test      # PHPUnit, needs SS_DATABASE_* with CREATE DATABASE privileges
```

## License

BSD-3-Clause
