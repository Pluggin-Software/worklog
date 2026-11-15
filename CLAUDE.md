# WorkLog module

Notes for working on `web/modules/WorkLog`, the Work Log (1.1.0; spec
`docs/superpowers/specs/2026-11-13-worklog-design.md`, plan
`docs/superpowers/plans/2026-11-13-worklog.md`).

**What it is.** "What I just did" in a few seconds, and a place to find it
again. One table, `worklog_entries`: `logged_at` is UTC, read and shown in the
site zone (`config['timezone']`); `body` is plain text, varchar(500), utf8mb4;
`deleted` is a soft delete. No roles: anyone signed in keeps their own. A new
column goes in `assets/install/worklog_entries.sql` AND a three-part migration
in `updates.php` (1.0.0 has none: the table installs on the first build).

**Private, in the SQL.** Every statement binds `:me` from `me()` (the session's
`user.user_id`), never a posted id. No role reaches another person's rows, an
Admin's or a Super User's included. Every `rout_`/`ajax_`/`submit_` method and
`widget()` opens with `$this->me()` and refuses signed out before it reads
(`HandlerGateTest`, `GateSweepTest`). Rows are posted as `entry_id`, never `id`
(`PostedIdTest`). A signed-out AJAX caller gets `login_prompt()`'s link through
`signed_out()`, which hands it the unpacked post (a modal's `$this->post` is
still the serialized blob).

**Three doors, one `submit_entry()`.**
- **The header +** (`quick_actions.index`, `modal` → `ajax_entry_form`): an
  `es_ajax_submit` form in `.es_modal`. A refusal re-renders it with what was
  typed. A save answers `~reload` when the form's `reload` field is 1, which
  its `onsubmit` sets when the page behind holds `.wl-log` (the widget and My
  log do). Otherwise it answers `logged.twig`, "Logged.", which closes its own
  modal. The server cannot tell which: the modal posts to whatever page is open.
- **The Today's log widget and the My log box:** a plain full-page POST (Enter
  saves, no script), answered by a redirect to `REQUEST_URI` (`back()`; never
  a URL from the form). A refusal keeps the text, the time and the message in
  `session['worklog_draft']`, and the next box drawn shows them once.
- **One template,** `entry-form.twig`, by `$door` (`modal`, `widget`, `page`).
  The count past 400 is an `oninput` attribute, so it works on the dashboard,
  which loads no module script. The module ships no JS. The widget and the
  modal link `styles.css` themselves.

**When** is the browser's own date-and-time picker (`input type=datetime-local`,
no script, so it works in the modal and the widget too), with `max` set to now
in the site zone (`Entry::picker_max()`); blank means now. It posts
`2026-11-12T14:15`. `Entry::parse_when()` still reads the older typed shapes,
so a hand-built post keeps working:
- empty or "now" is now;
- a time alone (`2:15 PM`, `2 PM`, `14:15`) is today;
- a date and time (`2026-11-12 2:15 PM`, `2026-11-12T14:15`,
  `11/12/2026 2:15 PM`) is that.

All are read in the site zone and parsed strictly (no February 30). Refused:
more than 5 minutes ahead, before 2000, anything else. The edit form writes
the time back as `Entry::WHEN_FORMAT` (`Y-m-d\TH:i`, the picker's own value).

**My log** (`rout_index`): the box, the list, the export.
- **The list** goes through `ListView`: shell `rout_index()`, fragment
  `ajax_entries_table()`, one `entries_table()`. `Search::where($q, ['body'])`
  does the searching (plain text, no `_text` sibling). It sorts by time only
  (`SORTS`), 50 a page, with the page clamped after the search, and a day row
  wherever the day changes.
- **The ⋯ menu:**
  - **Edit**: `ajax_edit_form` → `submit_edit`, in a modal, answers `~reload`.
  - **Delete**: `html_element_post` + `data-confirm`, a soft delete that
    answers the fragment with the search kept.
- **Export** is a GET form (no token): `rout_export` → `build_export()` →
  `Export::send()`.
  - Word goes through `Export::to_docx()`; CSV through `Exporter::csv()`
    (`escape: ''`, UTF-8 BOM).
  - The file is named `work-log-{from}-{to}`.
  - The default range is this week, Monday to Sunday.

**Account hooks** (`account_hooks`): `account_purge()` deletes every row of
the person; `account_export()` hands their live entries to the account export.

**Completed tasks** (`task_hooks`, core 2.98.0): `task_done(array $task, int
$user_id)` logs `[Task Completed] {title}` for whoever ticked it, at the
moment they ticked it — through the shared `record_entry()` insert, the same
one `submit_entry()` uses. `Entry::task_done_body()` strips a title's HTML
and entities, collapses whitespace to one line, then cuts the whole body
(prefix included) to `MAX_LENGTH`, multibyte-safe. `estuaries\TaskHooks`
reaches this only from a person's own tick on a task — the widget, the task
popup's Mark done, the Tasks module's own list, StaffWorkflow's own Mark
Done button (`ajax_complete_task()`, fix round 1), and an approver approving
or denying a Car & Room reservation (Checkout 1.10.0, `close_approval_task()`:
deciding is the approver's own act, so Checkout fires the hook itself after
closing their task, at most once, with a summary of the decision — core
2.98.6's `$summary`, which arrives here as the task's title: "Approved Dodge
Caravan for Anita Shelton, …") — never from
`CoreTasks::mark_task_as_done()` itself (StaffWorkflow's sync,
`reconcile_completed_tasks()`, calls that directly), never from
`mark_programmatic_task_as_done()` (every module using it — StaffWorkflow,
Conference, Bluesheets, Checkout — is closing a task as a side effect of a
different action: a form, an approval, a status change, never a generic
tick; a module whose action IS the person's own decision fires the hook
itself, as Checkout's approvals do), and never from `mark_task_as_custom_status()` (no caller at all). Not
in `REQUEST_METHODS`: nothing posts to it.

**The welcome guide** (`config.php` `welcome`, `WorkLog::guide_html()`):
mailed to every account on core role 3 (Authenticated User) — Work Log owns
no role of its own, so `core_roles` is how `WelcomeEmail::core_role_modules()`
finds it. `url` is left empty on purpose: the guide's own links are already
absolute (`$this->core->full_base_web_url . $this->guide_link()`, which finds
the page with `get_page_link_for_module()` whatever the sender's roles, so a
signup or cron send gets the real page), since a
relative href in a mail client resolves against nothing useful.
`UserGuideTest` pins the guide's headings in order and that its links are
absolute, real addresses.

**Tests:**
- `tests/Support/WorkLogHarness.php`: a constructorless module over FakeDB, the
  clock at 10:00 AM New York on Tue Nov 10 2026, signed in as 28,
  `REQUEST_URI` `/my-log`. It provides `handle()`, `writes()`, `selects()`, and
  the module's `redirected_to`.
- `tests/Unit/WorkLog/*`.
- The widget template is in `WidgetFrameTest`.

**Deploying:**
1. Switch Work Log on in Modules Admin. The first build creates the table,
   registers the widget on the site default layout (with no page yet — see
   below), and sends the welcome guide (`config.php`'s `welcome`, core role
   3) to every existing account with no further action needed. "Just me for
   now" in the design notes meant each person's log is private, not that
   only one person uses the module — every account may keep its own, so the
   welcome block stays on; there is nothing in it to restrict per-person.
2. Make a page (e.g. "My log" at `/my-log`) with Work Log as its app, open to
   Authenticated User (or a narrower role). **Both the header's + and the
   Today's log widget follow that page's roles** (`widget()` checks
   `links_resolved`, set by `module_links()` from the pages this visitor's
   roles admit): restricting the page restricts both, and until the page
   exists neither one's link resolves, so the widget shows nobody a tile at
   all (finding 2) and the + stays hidden. The welcome guide's own link
   resolves independently of any visitor's roles
   (`get_page_link_for_module()`, finding 8), so it works from the first
   send even before anyone's roles would otherwise find the page.

Anyone with their own dashboard arrangement adds "Today's log" from
**Add a widget** once the page exists and their roles admit it.
