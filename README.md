# silverstripe-flow

Domain-neutral case files ("Fallakte") for Silverstripe 6, built on
[`atwx/silverstripe-frontdesk-kit`](https://github.com/atwx/silverstripe-frontdesk-kit).

An extension turns any domain class into a case file with:

- phases and statuses with allowed transitions per role and a status history
- field permissions per field group × role × status (hidden / read / write)
- revocable edit links for external collaborators without an account (random token, stored as hash)
- custom fields stored as JSON, usable in forms, lists, filters and exports
- a timeline of notes, status changes and link accesses
- comments, follow-ups and to-dos on any record, with lists per member
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
the export; several statuses can be chosen, any of them matches), offers "Change status" with the member's transitions, and shows the
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
- a list column and a filter per responsibility (`Assignee_<code>`; several
  members can be chosen, any of them matches; a single ID from older links or
  saved views still works),
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
        body: |
          {{Block.Travel_Intro}}
          <p>Liebe Familie {{Child.Family.Surname}}, …</p>
          <ul>{{#Selected}}<li>{{School.Name}}: {{Visit}}</li>{{/Selected}}</ul>
          <p>{{FreeText}}</p>
          <p>{{Signer.Name}}</p>
        select: Applications       # entries chosen in the dialog ({{#Selected}})
        free_text: true            # field "Free text" in the dialog ({{FreeText}})
        signer: consultant         # default signer: the member of this responsibility
        blocks: Tests              # text blocks of this category to choose (true = all)
```

**Placeholders:**
- `{{Field}}`, `{{Relation.Field}}`: has_one chains. Dates come as dd.mm.yyyy,
  date and time as dd.mm.yyyy hh:mm. Getters like `{{Child.Title}}` work; no
  other methods are called.
- `{{Flow.<code>}}` (custom field), `{{Assignee.<code>}}` (responsibility),
  `{{Status}}`, `{{Today}}`, `{{Me.Name}}` and `{{Link}}` (the absolute address
  of the case file, through its routed controller).
- A default after a bar fills empty values: `{{Phone|-}}`.
- Values are HTML-escaped; unknown placeholders stay empty.

**Loops:** `{{#Relation}}…{{/Relation}}` repeats the block for every record
of a has_many or many_many relation; placeholders inside refer to that record.
Options: `sort=Field` (`sort=-Field` descending, relation paths allowed),
`filter=Field` (only records where the value is not empty) and
`filter=Field:value`, e.g. `{{#Applications sort=Visit filter=Visit}}`.

**Text blocks:** `{{Block.<Code>}}` inserts a text block (`TextBlock`) as
HTML, the only placeholder that is not escaped. A block may contain
placeholders and other blocks. Text blocks are maintained in the CMS (*Flow* →
*Text blocks*) or in the frontdesk with `TextBlockController` (route it, e.g.
`'text-blocks//$Action/$ID': Atwx\Flow\Controller\TextBlockController`,
and grant its permissions). In mails (plain text) a block is inserted without
its tags.

**Dialog "Create document":** the record action *Create document* (members
who may edit and have a role in the workflow) opens a form with:
- the entries of the `select` relation (all chosen at first), as `{{#Selected}}`,
- the free text (with `free_text`) as `{{FreeText}}`, line breaks kept,
- the signer (`{{Signer.Name}}`, `{{Signer.Email}}`): candidates of the
  workflow's responsibilities, preset with the member of `signer`, else the
  current member,
- the text blocks of the `blocks` category (`true`: all) to choose, as
  `{{#SelectedBlocks}}<h2>{{Title}}</h2>{{Body}}{{/SelectedBlocks}}`; `{{Body}}`
  is the block as HTML with its placeholders filled for the case file. Text
  blocks get a category in their form,
- *Preview* (the HTML in a new tab, scripts disabled) and *Create*.

The sub-list *Documents* has download
(`<segment>/documents/<ID>/download/<document>`, only for members who may view
the case file), *Edit* (the dialog with the stored inputs; saving creates the
PDF again from the current data) and *Delete*. The timeline lists created
documents. The PDF is stored protected (`DBFile`, not in the asset admin) and
deleted with the case file. Attaching a new PDF to a mail creates it with all
entries and the default signer.

**Letterhead:** the body is rendered into the template
`Atwx\Flow\Document\FlowDocument` — override it in the project for the
letterhead. It gets `$Title`, `$Body`, `$Case`, `$Template`, `$Date`,
`$Signer`, `$FreeText` and `$Selected`. For more data, give the case class a
method `flowDocumentData(DocumentTemplate $template, ?Member $signer): array`,
or add an extension to `DocumentService` with
`updateFlowDocumentData(array &$data, DataObject $case, DocumentTemplate $template)`.

PDFs are made by the `PdfRenderer` service, by default with
`atwx/silverstripe-headless-pdf` (headless Chrome; the HTML is printed from a
temporary file, nothing is served through a URL).

## Comments, follow-ups and to-dos

Comments (`Comment`) belong to any record: case files, but also persons,
schools or anything else with a frontdesk controller. Each comment has:

- **Kind:** *Memo*, *Wiedervorlage* (follow-up), *To-Do* or *Termin*
  (appointment). The kind, not the type name, decides in which lists an entry
  appears.
- **Type:** an entry of the catalog `flow_comment_type` (*Kommentartypen*,
  maintained under the catalogs; the default entry, *Memo* on a new
  installation, is preset).
- **Text:** up to 2000 characters (`Comment.max_length`).
- **Date and time:** both optional.
- **Responsible member:** optional.
- **Marks:** *Wichtig* (important) and *Erledigt* (done).
- **Author:** the author stays when someone else edits the comment
  (`CreatedBy`, `LastEditedBy`).

The sub-list *Kommentare* shows entries with a date from today on first (by
date), then the important ones, then the rest (latest change first). Entries
that are no memos show the kind and the member responsible in front of the
text: *Wiedervorlage (Anna): Schule anrufen*. Follow-ups and to-dos are marked
as done instead of deleted. Creating and closing appear in the timeline of a
case file, and comments are deleted with their record.

Every `FlowCaseController` has the sub-list. Other records get it in two
lines:

```yaml
App\Models\School:
  extensions:
    - Atwx\Flow\Extension\CommentsExtension          # comments, deleted with the record
App\Controllers\SchoolController:
  extensions:
    - Atwx\Flow\Extension\CommentsControllerExtension  # sub-list <segment>/comments/<ID>
```

A record can keep its comments on another record. For example, a student
record can use the comments of its child. To do so, give the model a method
`flowCommentRecord(): DataObject` (see `Comment::recordFor()`). The sub-list,
the follow-ups that rules create and the timeline then all use that record.
Its class needs `CommentsExtension`. A controller can also map the record with
`commentRecordFor(DataObject $record): DataObject`, but that only changes its
own sub-list.

`Comment::ensureDefaultType()` creates the type *Memo* when the catalog is
empty, e.g. after a seeder has emptied the catalogs.

Follow-up rules create follow-ups when a case file enters a status:

```yaml
    follow_ups:
      - code: chase_schools
        title: Schulen nachfassen
        status: profile_sent         # entering this status creates the follow-up
        days: 14                     # due after 14 days
        responsibility: responsible  # for the member responsible (optional)
        close_on_leave: true         # default: closed when the status is left
```

`FollowUpController` lists the open follow-ups and to-dos of all records whose
list the member may view. Route it in the project:

```yaml
SilverStripe\Control\Director:
  rules:
    'followups//$Action/$ID/$OtherID': Atwx\Flow\Controller\FollowUpController
```

- **Views:** *Meine fälligen*, *Meine Wiedervorlagen*, *Meine To-Dos*,
  *To-Do-Korb* (to-dos without anyone responsible) and *Überfällig*.
- **Filters:** by kind and by member.
- **Bulk actions:** *Als erledigt markieren* and *Übernehmen* (enters the
  member as responsible).

**Upgrading from follow-ups:** follow-ups of earlier versions (`FollowUp`,
table `Flow_FollowUp`) become comments of kind follow-up on `sake db:build`.
The old table is renamed to `_obsolete_Flow_FollowUp`. In code:

- `FollowUp` becomes `Comment` with `Kind` `followup`.
- `CaseClass` and `CaseID` become `RecordClass` and `RecordID`.
- `FlowFollowUps()` becomes `FlowComments()`.
- `FollowUpSubController` becomes `CommentSubController`, segment `comments`
  instead of `followups`.

`Note` stays the unchangeable note in the timeline of a case file.

### Daily mail about due follow-ups

`sake flow:followup-digest` sends every member one mail with their open
follow-ups and to-dos that are due today or overdue, each with a link to the record,
plus a link to the routed `FollowUpController` (view *Meine fälligen*). Run it
once a day by cron:

```
0 7 * * *  cd /path/to/site && vendor/bin/sake flow:followup-digest
```

- Only entries of records whose list the member may view; entries without a
  member are left out.
- At most one mail per member and day (`--force` sends again); `--dry-run`
  only reports who would get one.
- Members turn it off with *Keine tägliche Mail zu fälligen Wiedervorlagen*
  (`Member.FlowDigestOff`, in the security admin).
- Links are absolute: set `SS_BASE_URL` for the command line.
- Sender as for mails (`MailService.from`); a failed mail is logged and
  reported, the others are still sent.

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

## Mails

Mail templates belong to a workflow (CMS: workflow → *Mail templates*, or YAML).
Each template has the following parts:

- **Subject and body:** plain text with the placeholders of the document
  templates.
- **Recipients, Cc and Reply-To:** expressions, comma-separated. Possible tokens:
  - addresses, e.g. `info@example.org`
  - fields of the case file, also through has_one, has_many and many_many
    relations, e.g. `Email`, `Contact.Email`, `Family.Parents.Email`
  - `Assignee.<responsibility>`, the member assigned to that responsibility
  - `Me`, the member who writes the mail
  - `Item.<field path>`, the entry of a mail per entry (see below)

  Invalid addresses are left out. A domain class can add tokens of its own with
  `updateFlowMailRecipients(string $token, array &$addresses)`.
- **Attachments:** codes of document templates. Their PDFs are created and
  attached, and they are also kept as documents of the case file.
- **Files:** files from the asset library (CMS only, not in the YAML file),
  attached as they are at sending time, e.g. the current price list. Replacing
  a file in the CMS sends its new version from then on.
- **Download links:** with `links: true` PDFs and files are not attached; the
  text gets a download link for each instead (see below).
- **Automatic sending:** `send_on` sends the mail when the case file enters that
  status. The mail goes out after the status change, as the actor (the member, or
  nobody for an edit link).
- **Notification about a new case file:** with `send_on` set to the initial
  status, the mail is also sent when a case file is created, e.g. by a public
  form (see below).

```yaml
    mails:
      - code: confirmation
        title: 'Bestätigung senden'
        subject: 'Bestätigung: {{Title}}'
        to: 'Contact.Email, Assignee.lead'
        cc: Me
        reply_to: Assignee.lead
        body: "Guten Tag,\n\nder Kurs {{Title}} …"
        attach: [confirmation]        # document templates
      - code: closed
        title: Abschlussmeldung
        subject: 'Abgeschlossen: {{Title}}'
        to: info@example.org
        body: 'Der Kurs {{Title}} ist abgeschlossen.'
        send_on: done                 # sent automatically
```

**Writing a mail from a case file:**

1. The record action *Mail schreiben* lists the templates. It is offered to
   members who may edit and have a role in the workflow.
2. It opens the mail form `<segment>/mails/<ID>/compose`, filled from the
   template. Recipients, subject, text and attachments can be changed there. As
   attachments, the form offers new PDFs from document templates and the
   existing documents of the case file.
3. *Senden* sends the mail. *Im Mailprogramm öffnen* downloads it as an `.eml`
   file with `X-Unsent: 1`, which Outlook and Thunderbird open as a draft.

**One mail per entry of a sub-list:** with `for_each` (a has_many or
many_many relation of the case file) one mail is sent per entry, e.g. to every
school of an application. `{{Item.<field>}}` in subject and body and
`Item.<field path>` in the recipients refer to the entry:

```yaml
      - code: request_school
        title: 'Anfrage an Schulen'
        for_each: SchoolApplications
        subject: 'Anfrage: {{Child.Name}} an {{Item.School.Name}}'
        to: Item.School.ContactEmail
        body: "Guten Tag,\n\nwir möchten {{Child.Name}} …"
```

- The mail form lists the entries with their recipients; entries without a
  valid address cannot be chosen. Subject and body keep their placeholders and
  are filled per entry when sending; a preview shows the first entry. There is
  no mail file (*Im Mailprogramm öffnen*) for these templates.
- With `send_on`, a mail goes to every entry.
- PDFs of document templates are created once and attached to every mail.
- Each mail is recorded in the case file and refers to its entry
  (`CaseMail.Item`; `CaseMail::forItem($entry)` lists the mails of an entry).

**Notification about a new case file:** a template with `send_on` on the
initial status goes out when a record is created in it, from the frontdesk, a
public form or code. Without recipients no mail is sent (the template cannot be
saved without them).

```yaml
      - code: new_application
        title: 'Neue Kurzbewerbung'
        subject: 'Neue Kurzbewerbung: {{FirstName}} {{LastName}}'
        to: 'office@example.org, Assignee.lead'
        send_on: received             # the initial status
        body: "Geburtsdatum: {{BirthDate|-}}\nTelefon: {{Phone|-}}\nEingang: {{Created}}\n\n{{Link}}"
```

- Records created with an explicit status (`CaseFileExtension::writeStatus()`,
  as imports and seeds do) send nothing.
- Imports that create records through the normal write run inside
  `MailService::withoutAutomaticMails(fn () => …)`, which turns off all
  automatic mails for that time.
- The mail is sent right after the first write. Records of sub-lists that are
  written afterwards are not in it yet.

**Download links instead of attachments:** with `links: true` (CMS:
*Anhänge als Download-Links*; the mail form can switch it per mail) the mail
ends with a block of links:

```
Downloads (bis 12.12.2026):
- Preisliste: https://example.org/flow/download/<token>
- Reisebrief Anna: https://example.org/flow/download/<token>
```

- A link opens without login at `flow/download/<token>` and stays valid for
  `MailDownload.valid_days` days (default 30; 0: no expiry). Expired links
  answer 410, unknown ones 404.
- Only a hash of the token is stored (`MailDownload`, one per file, deleted
  with the mail). Downloads are counted (`Downloads`, `LastDownloadAt`).
- A file of the template is served in its current version, so a corrected
  file reaches whoever downloads it later. Documents of the case file are
  served as created.
- The recorded mail keeps the text with the links.

**Record of every mail:** each mail is kept as `CaseMail` with its full text.
It appears in the sub-list *Mails* and in the timeline as sent, not sent or
created for the mail program. A transport error never stops the work: the mail
is recorded as *nicht gesendet* together with the error, and the error is
logged.

The transport is Silverstripe's mailer (Symfony Mailer, `MAILER_DSN`), so SMTP
or another transport such as Microsoft Graph can be configured without code
changes. The sender is `MailService.from` (default `Email.admin_email`).

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
choice, choice from a catalog), field group and options. Values are stored as JSON on the case file
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
      - code: channel
        title: Akquisekanal
        type: catalog             # entries of a frontdesk-kit catalog
        catalog: acquisition_channel
        group: basics
```

A field of type `catalog` offers the active entries of a
[frontdesk-kit catalog](https://github.com/atwx/silverstripe-frontdesk-kit/blob/main/docs/catalogs.md)
(the catalog is declared in config and maintained under `catalogs/`). It stores the
entry's code. An inactive entry stays on the case files that have it, and so does
the filter on it. An entry stored on a case file counts as in use, so it cannot be
deleted.

## JSON API

A read-only JSON API serves other systems, such as a portal that runs as its
own site. It does not use GraphQL, and the project lists exactly what goes
out:

```yaml
Atwx\Flow\Api\ApiController:
  token_env: [PORTAL_API_TOKEN]          # environment variables with valid tokens
Atwx\Flow\Api\ApiResources:
  resources:
    family:
      class: App\Models\Family
      get: true                          # GET flow-api/family/<id>
      key: Number                        # optional: GET flow-api/family/<Number> instead
      fields:
        id: ID
        surname: Surname                 # database field
        status: StatusTitle              # getter getStatusTitle(), only when listed
        school: PlacementSchool.Name     # has_one path
        image: { file: Image }           # URL of flow-api/file/family/<id>/image
        children: { relation: Children, resource: child }
        documents: { relation: Documents, resource: document, filter: { InPortal: 1 }, sort: 'Created DESC' }
    schools:
      class: App\Models\School
      list: true                         # GET flow-api/schools?Active=1&limit=50&offset=0
      filter: { Active: 1 }              # always applied
      params: [Region]                   # filters the client may set
      fields: { id: ID, name: Name }
```

- **Key:** with `key: <field>`, `GET flow-api/<resource>/<value>` finds the
  record by that database field (exact, case-sensitive) instead of the ID. It
  answers 404 unless exactly one record matches, so the field should be unique.
  File URLs keep the ID. Use it when the client only knows another number, and
  keep `list` off so the client cannot enumerate records.
- **Requests:** `GET flow-api/<resource>/<id>`, `GET flow-api/<resource>` and
  `GET flow-api/file/<resource>/<id>/<field>`. A list answers
  `{total, limit, offset, items}`; `limit` is at most 500
  (`ApiController.max_limit`).
- **Key:** header `Authorization: Bearer <key>` or `X-Api-Token: <key>`, an
  API client's key with the scope `resources:<resource>` (or `resources:*`), or
  a token from `token_env`, which may call everything. A wrong key answers
  401, a missing scope 403. Without any active client or token, the whole API
  answers 404.
- **What goes out:** only listed fields and resources. A resource without
  `get` or `list` is only reachable nested inside another one, and an unknown
  list parameter answers 400. That way a client cannot query other families
  through lists.
- **Values:** numbers and booleans as JSON types, dates as `YYYY-MM-DD`.
  Getters are only called when they are listed, and nesting stops after
  `ApiResources.max_depth` levels (default 6).

## API clients, log and gateway

Other systems call the API with their own key. Clients are records (CMS
section *Workflows → API clients*, or `sake flow:api-client`):

```bash
sake flow:api-client "Website" --scope=intake:application --rate=30
sake flow:api-client --renew=3     # new key, the old one stops working
sake flow:api-client --list
```

- **Key:** `fk_<prefix>_<secret>`, shown once after creating or renewing it.
  Only its SHA-256 hash is stored.
- **Scopes:** one per line: exact (`intake:application`), a prefix
  (`intake:*`) or everything (`*`). Tokens from `ApiController.token_env`
  still work and have all scopes, so existing setups keep running.
- **Rate limit:** requests per minute per client (`RateLimit`; 0 =
  `ApiClient.default_rate_limit`, 60; -1 = unlimited). Over it: 429 with
  `Retry-After: 60`. Tokens from the environment are unlimited.
- **Log:** every request lands in `ApiLog` with the client, the operation
  (e.g. `intake.application.create`, `resources:family:get`), method, status,
  duration and IP. Payloads, query strings and record keys are never logged,
  because requests may carry personal data. `sake flow:retention` (daily
  cron) deletes entries after `ApiLog.retention_days` (90).
- **Errors:** always JSON: `{"error": "…"}`, with field errors for 422:
  `{"error": "Validation failed", "fields": {"email": "Required"}}`. Status
  codes: 401 wrong key, 403 missing scope, 404, 405, 413 body too large
  (`ApiRuntime.max_body_bytes`, 25 MB, or per operation), 422, 429, 500
  (details only in the server log).

Projects add their own operations below `api/` with an endpoint:

```php
class PortalEndpoint implements ApiEndpoint
{
    public function operations(): array
    {
        return [
            ApiOperation::create('portal.login', 'POST', 'portal/login', 'portal:login',
                function (HTTPRequest $request, array $params, ApiClient $client) {
                    $data = json_decode((string) $request->getBody(), true);
                    if (empty($data['number'])) {
                        throw ApiException::invalid(['number' => 'Required']);
                    }
                    return ['allowed' => true];          // JSON, status 200
                })
                ->summary('Check a portal login')
                ->requestSchema(['type' => 'object', 'required' => ['number']]),
        ];
    }
}
```

```yaml
Atwx\Flow\Api\ApiGateway:
  endpoints:
    portal: App\Api\PortalEndpoint
```

Path segments in braces are parameters (`submissions/{reference}` →
`$params['reference']`). `status(201)` sets the success status;
`maxBodyBytes()` the body limit. `GET api/openapi.json` returns an OpenAPI
3.1 document of the operations and read resources the calling key may use
(title and version: `OpenApi.title`, `OpenApi.version`; hook
`updateOpenApi`).

## Inbox (form submissions)

External forms (a website, friendlydesk) push submissions into the inbox
("Eingang") through the API instead of the site polling them. Staff look at
them, correct them and take them over into the project's records; a
retention period deletes them afterwards. Form types are declared in config:

```yaml
Atwx\Flow\Intake\IntakeService:
  types:
    application:                         # POST api/intake/application, scope intake:application
      title: Kurzbewerbung
      reference_prefix: KB               # KB-2026-0001
      summary: '{{child.first_name}} {{child.last_name}}'   # list column, mail placeholder
      reply_to: parent_email             # Reply-To of the notification
      fields:
        parent_email: { type: email, title: 'E-Mail der Eltern', required: true }
        child:
          type: group
          title: Kind
          fields:
            first_name: { type: string, title: Vorname, required: true, max: 100 }
            birthday: { type: date, title: Geburtsdatum }
        english_years: { type: integer, title: 'Jahre Englisch', min: 0, max: 20 }
        sports: { type: choice, multiple: true, options: { tennis: Tennis, golf: Golf } }
        siblings:
          type: repeat
          title: Geschwister
          max_items: 6
          fields: { first_name: { type: string, required: true } }
        consent: { type: boolean, accepted: true, required: true }
      files:
        reports: { title: Zeugnisse, types: [pdf, jpg, png], max_size: 10MB, max_count: 3 }
      notify:
        to: 'office@example.org'         # list or comma separated
        subject: 'Neue Kurzbewerbung {{Reference}}'
        block: Kurzbewerbung_Eingang     # text block as mail body (optional)
        fields: [child.first_name]       # the only data allowed in the mail: {{Field.child.first_name}}
      receipt: { hours: 24 }             # receipt PDF link in the answer (optional)
      retention: { new: 0, taken_over: 30, discarded: 30 }   # days after the last status change; 0 = keep
      takeover: App\Intake\ApplicationTakeover
```

- **Field types:** `string` (`max`, default 255), `text` (default 10000),
  `email`, `date` (`YYYY-MM-DD`), `integer`/`number` (`min`, `max`),
  `boolean` (`accepted: true` = must be true), `choice` (`options`,
  `multiple`), `list` (strings), `group` (object with `fields`) and `repeat`
  (list of objects with `fields`, `max_items`). Each with `title`,
  `required`, `help`. Unknown fields are refused, so nothing unexpected is
  stored.
- **Request:** `POST api/intake/<type>` with a key that has the scope
  `intake:<type>`. JSON:
  `{"data": {…}, "files": {"reports": [{"name": "zeugnis.pdf", "content": "<base64>"}]}}`,
  or `multipart/form-data` with a part `data` (the JSON object) and file parts
  named after the file fields (`reports[]`). One call carries the whole
  submission. The body limit follows from the file limits.
- **Files:** the extension must be listed in `types`, and the content must
  match it (checked with finfo, `IntakeService.mime_types`). Files are stored
  as protected (unpublished) files named after the field
  (`intake/<type>/<reference>/reports-1.pdf`); the submitter's file name is
  only kept on the submission.
- **Answer:** 201 `{"reference": "KB-2026-0001", "status": "new", "receipt":
  {"url": "…/flow/receipt/<token>", "expires": "…"}}`. 422 lists field errors
  by path (`child.first_name`, `siblings.1.first_name`, `files.reports.0`).
- **Idempotency:** with the header `Idempotency-Key`, a repeated call of the
  same client returns the first submission (200, header
  `Idempotent-Replayed: true`) instead of creating another; the same key for
  another form type answers 409.
- **Notification:** a mail to `notify.to` after the submission is stored. A
  failure is logged and shown on the submission; the submission is accepted
  anyway. The body is the text block `notify.block` (maintained by the staff)
  or a short default. Placeholders: `{{Reference}}`, `{{Type}}`,
  `{{Summary}}`, `{{Link}}` (to the inbox), `{{Date}}` and
  `{{Field.<path>}}` only for the paths in `notify.fields`, so personal data
  only goes out where the type allows it.
- **Receipt:** `flow/receipt/<token>` renders a PDF of the submission
  (template `Atwx\Flow\Intake\IntakeReceipt`, or `receipt.template`;
  `receipt.block` adds a text block on top) without login. The token is
  random, stored as a hash and valid for `receipt.hours` (24). Hook
  `updateReceiptData`.
- **Inbox:** route `Atwx\Flow\Intake\IntakeController`
  (`'intake//$Action/$ID/$OtherID'`) and grant `FDK_INTAKE_VIEW` (view) or
  `FDK_INTAKE_EDIT` (correct, take over, discard, delete); a type may require
  an additional permission code (`permission`). The list filters by form,
  status and search; the record shows the data by the form type, the files
  (protected download) and what it was taken over into. *Edit* corrects the
  data in a form generated from the type.
- **Takeover:** the type's handler implements `TakeoverHandler`:

  ```php
  class ApplicationTakeover implements TakeoverHandler
  {
      public function choices(Submission $submission): array
      {
          // offered in the dialog, e.g. matching families
          return ['new' => 'Neue Familie'] + $this->matchingFamilies($submission);
      }

      public function takeOver(Submission $submission, ?string $choice, Member $member): array
      {
          $family = $choice === 'new' ? Family::create() : Family::get()->byID((int) $choice);
          // … map $submission->value('child.first_name') etc.
          foreach ($submission->filesOf('reports') as $file) {
              $submission->claimFile($file);       // keep it: retention leaves it alone
              $file->ParentID = …; $file->write();
          }
          return [$family, $child];              // linked from the submission
      }
  }
  ```

  It runs in a transaction; `TakeoverException` refuses with a message. The
  submission is then *taken over*, with who and when and links to the
  records (`updateTakeoverLink` on the controller for their URLs). *Discard*
  marks it as not taken over.
- **Retention:** `sake flow:retention` (daily cron) deletes submissions with
  their files and folders once `retention.<status>` days have passed since
  the last status change. Claimed files stay.
- **Hooks:** `onAfterSubmit(Submission)`, `updateNotifyEmail(Email,
  Submission)`, `onAfterTakeOver(Submission, Member)`,
  `updateReceiptData(ArrayData, Submission)` on `IntakeService`.

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
