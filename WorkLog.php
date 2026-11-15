<?php
declare(strict_types=0);

namespace estuaries_modules\WorkLog;

use DateTimeImmutable;
use DateTimeZone;
use estuaries\Core;
use estuaries\Export;
use estuaries\ListView;
use estuaries\Modules;
use estuaries\ModulesInterface;
use estuaries\Search;
use estuaries\Templater;

/**
 * Work Log: a few seconds to note what you just did, and a place to find it
 * again (spec docs/superpowers/specs/2026-11-13-worklog-design.md).
 *
 * PRIVATE TO ITS WRITER. Every statement binds :me, the signed-in person's
 * own user id from the session (me()), in the SQL itself, never an id from
 * the request; no role, an Admin's or a Super User's included, reaches
 * anyone else's entries. Signed out, every door refuses before it reads.
 *
 * Times are stored in UTC (logged_at) and read and shown in the site's zone
 * (site_zone()), so "today" is the site's today.
 *
 * Three doors add an entry, all into submit_entry():
 *  - the header's + (config quick_actions): ajax_entry_form() in a modal,
 *    es_ajax_submit. A refusal re-renders the form with what was typed; a
 *    save answers ~reload when the form's reload field is 1 (its onsubmit
 *    sets it when the page behind holds .wl-log: the widget, My log), and
 *    otherwise "Logged.", which closes its own modal;
 *  - the dashboard widget and the My log box: a plain full-page POST, so
 *    Enter saves with no script, answered by a redirect back (back()). A
 *    refusal keeps what was typed for the next box drawn (keep_draft()).
 */
class WorkLog extends Modules implements ModulesInterface
{
    /** What a request may name. Each opens with $this->me(). */
    public const REQUEST_METHODS = [
        'widget', 'ajax_entry_form', 'submit_entry',
        'ajax_entries_table', 'ajax_edit_form', 'submit_edit', 'submit_delete',
    ];

    /** My log's list: the div every search, sort, page turn and delete replaces. */
    public const LIST_TARGET = 'wl-entries';

    /** Sort key => column. ORDER BY cannot be bound, so a request only ever names a key. */
    private const SORTS = ['time' => 'logged_at'];

    /** An entry id that is not the caller's, or is gone: the same answer, so nothing is learned. */
    private const GONE = "<div class='alert alert-warning'>That entry isn't in your log.</div>";

    /** modal.js reloads the page on exactly this answer, inside a modal only. */
    private const RELOAD_TOKEN = '~reload';

    /** Where a refused page post leaves what was typed, for the next box drawn. */
    private const DRAFT_KEY = 'worklog_draft';

    /**
     * Set by back() for the render Router performs in the very same POST
     * (Router::process(): a non-ajax result falls through to $this->view(),
     * which runs rout_index() -> entry_form('page') -> take_draft() before
     * the browser ever acts on the Location header). That render's body is
     * thrown away, so it must not show the draft, and must not consume it
     * either — the same trap Core::get_message() already guards against for
     * messages (is_redirecting(), Core.php). A new PHP process starts this
     * false on its own; nothing here resets it mid-request on purpose.
     */
    private static bool $redirecting = false;

    private const SIGN_IN_TO_KEEP = 'Sign in to keep a work log.';
    private const SIGN_IN_TO_SEE  = 'Sign in to see your work log.';
    private const SAVE_FAILED     = "Couldn't save that. Try again in a minute.";
    private const DELETE_FAILED   = "Couldn't delete that. Try again in a minute.";

    /** Tests: the clock. */
    public ?DateTimeImmutable $now_override = null;

    /** Where back() last sent the browser (tests read it; header() is silent on the CLI). */
    public ?string $redirected_to = null;

    function __construct(Core $core, $get = [], $post = [], $session = [], $module_name = __CLASS__, $attachment_id = null, $alternate_url_up_to_module = null, $module_page_vars = null)
    {
        parent::__construct($core, $get, $post, $session, $module_name, $attachment_id, $alternate_url_up_to_module, $module_page_vars);

        // module_links() before loader(): loader() builds $this->links from
        // the page module_links() finds.
        $this->module_links(__CLASS__);
        $this->url_array = $this->core->module_url_array;

        $this->loader();
    }

    public function process($get = [], $post = [])
    {
        return parent::view($get['id'] ?? null, $get, $post);
    }

    public function add($get = [], $post = []) {}

    public function edit($id, $get = [], $post = []) {}

    public function delete($id, $get = [], $post = []) {}

    public function form_prep(array $vars_array)
    {
        return $vars_array;
    }

    /**
     * My log: the box, then every entry as a searchable, sortable, paged
     * list (ListView's shell; the fragment is entries_table()), then the
     * export. The search box sits outside the target div, which every reply
     * replaces.
     */
    public function rout_index($get = [], $post = []): string
    {
        $me = $this->me();
        if ($me < 1) {
            return $this->page_wrapper($this->login_prompt(self::SIGN_IN_TO_SEE));
        }

        $list                  = $this->entries_list();
        [$week_from, $week_to] = Exporter::this_week($this->now(), $this->site_zone());

        return $this->page_wrapper(Templater::fill_template($this->module_path . '/assets/templates/page.twig', null, [
            'box'        => $this->entry_form('page'),
            'search_box' => $list->search_box('', 'Search your log', [], 'Search your work log'),
            'target'     => $list->target(),
            'table'      => $this->entries_table($me, []),
            'export_url' => (string) ($this->links['export'] ?? ''),
            // A refused export range (rout_export -> rout_index) re-renders
            // with the dates the caller actually typed, not silently reset
            // to this week.
            'from'       => $this->valid_date((string) ($get['from'] ?? '')) ?? $week_from->format('Y-m-d'),
            'to'         => $this->valid_date((string) ($get['to'] ?? '')) ?? $week_to->format('Y-m-d'),
        ]));
    }

    /** $value kept only when it looks like a Y-m-d date; otherwise null, so the caller falls back to this week. */
    private function valid_date(string $value): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }

    /** The header's +: the box, for a modal. Reads nothing. */
    public function ajax_entry_form($get = [], $post = []): string
    {
        $me = $this->me();
        if ($me < 1) {
            return $this->signed_out($post, self::SIGN_IN_TO_KEEP);
        }

        return $this->entry_form('modal');
    }

    /**
     * Every door saves here, as the caller: me(), never a posted user_id.
     * AJAX (the modal): the form again with the problem, or ~reload, or
     * "Logged.". A page post: a redirect back to the page that posted.
     */
    public function submit_entry($get = [], $post = []): string
    {
        $me = $this->me();
        if ($me < 1) {
            return $this->signed_out($post, self::SIGN_IN_TO_KEEP);
        }

        $values = ['body' => (string) ($post['body'] ?? ''), 'when' => (string) ($post['when'] ?? '')];
        $check  = Entry::check($values['body'], $values['when'], $this->now(), $this->site_zone());

        if ($check['error'] === null) {
            $saved = $this->record_entry($me, $check['at'], $check['body']);
            $error = $saved ? null : self::SAVE_FAILED;
        } else {
            $error = $check['error'];
        }

        if ($this->is_ajax($post)) {
            if ($error !== null) {
                return $this->entry_form('modal', $values, $error);
            }

            return ($post['reload'] ?? '') === '1'
                ? self::RELOAD_TOKEN
                : Templater::fill_template($this->module_path . '/assets/templates/logged.twig', null, []);
        }

        if ($error !== null) {
            $this->keep_draft($values, $error);
        }

        return $this->back();
    }

    /**
     * The one INSERT every door uses, for a body and instant already
     * validated or built by the caller — submit_entry() for a typed entry,
     * task_done() for a completed task's title.
     */
    private function record_entry(int $user_id, DateTimeImmutable $at, string $body): bool
    {
        $now = self::utc($this->now());

        return (bool) $this->db->insert(
            'INSERT INTO worklog_entries (user_id, logged_at, body, deleted, date_created, date_updated)
             VALUES (:me, :logged_at, :body, 0, :created, :updated)',
            ['me' => $user_id, 'logged_at' => self::utc($at), 'body' => $body, 'created' => $now, 'updated' => $now]
        );
    }

    /**
     * core 2.98.0 task hooks' 'done' kind: a person ticking a task done logs
     * it here too, for them, as "[Task Completed] {title}" at the moment it
     * happened. Reached only through estuaries\TaskHooks::call() — never a
     * request, so NOT in REQUEST_METHODS — and only from the person paths
     * CoreTasks fires it from (never a module closing its own bookkeeping
     * task). $user_id is the person who ticked it, not necessarily whoever
     * is in this request's own session.
     */
    public function task_done(array $task, int $user_id): void
    {
        if ($user_id < 1) {
            return;
        }

        $this->record_entry($user_id, $this->now(), Entry::task_done_body((string) ($task['title'] ?? '')));
    }

    /**
     * The entry box, one template for the three doors: 'modal' (the +, by
     * AJAX), 'widget' and 'page' (a plain POST). A draft left by a refused
     * page post fills the next widget or page box, once.
     */
    public function entry_form(string $door, array $values = [], string $error = ''): string
    {
        if ($door !== 'modal' && $values === [] && $error === '') {
            [$values, $error] = $this->take_draft();
        }

        return Templater::fill_template($this->module_path . '/assets/templates/entry-form.twig', null, [
            'door'       => $door,
            'modal'      => $door === 'modal',
            'css'        => $door === 'modal' ? $this->css_url() : null,
            'body'       => (string) ($values['body'] ?? ''),
            'when'       => (string) ($values['when'] ?? ''),
            'when_max'   => Entry::picker_max($this->now(), $this->site_zone()),
            'error'      => $error,
            'max_length' => Entry::MAX_LENGTH,
            // the dashboard must not steal focus; the modal focuses itself
            'autofocus'  => $door === 'page',
        ]);
    }

    public function page_wrapper($content = null): string
    {
        return Templater::fill_template($this->module_path . '/assets/templates/page-wrapper.twig', null, [
            'content' => (string) $content,
        ]);
    }

    /** The module stylesheet, versioned by its mtime, for the places that link it themselves. */
    private function css_url(): string
    {
        $file = $this->module_path . '/assets/css/styles.css';

        return '/modules/WorkLog/assets/css/styles.css' . (is_file($file) ? '?v=' . filemtime($file) : '');
    }

    /**
     * The refusal for anyone signed out, which writes nothing: a sign-in
     * link for AJAX, a message for a page post.
     *
     * login_prompt() chooses its link by $this->post['action'], and for a
     * modal's submit $this->post is still the serialized `data` blob
     * (PostRouter builds the module with the raw post and hands the handler
     * the unpacked one), so it is given the unpacked post first. Without
     * that a modal would show a whole login form, which modal.js would post
     * by AJAX.
     */
    private function signed_out(array $post, string $message): string
    {
        if ($this->is_ajax($post)) {
            $this->post = $post;

            return $this->login_prompt($message);
        }

        $this->core->set_message_error($message);

        return '';
    }

    /**
     * Post/redirect/get: back to the page that posted, never to a URL the
     * form names. Core::get_message() holds a message back while the
     * Location header is queued, so it shows on the page they land on.
     */
    private function back(): string
    {
        $to = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        if (!str_starts_with($to, '/') || str_starts_with($to, '//')) {
            $to = '/';
        }

        self::$redirecting  = true;
        $this->redirected_to = $to;
        if (!headers_sent()) {
            header('Location: ' . $to);
        }

        return '';
    }

    private function keep_draft(array $values, string $error): void
    {
        $this->core->session[self::DRAFT_KEY] = ['body' => $values['body'], 'when' => $values['when'], 'error' => $error];
    }

    /** @return array{0:array{body?:string,when?:string},1:string} the draft and its message, then gone */
    private function take_draft(): array
    {
        // This render's body is discarded by the browser (back()'s doc
        // comment), so it shows nothing here and leaves the draft untouched
        // for the request that actually lands.
        if (self::$redirecting) {
            return [[], ''];
        }

        $draft = $this->core->session[self::DRAFT_KEY] ?? null;
        unset($this->core->session[self::DRAFT_KEY]);

        if (!is_array($draft)) {
            return [[], ''];
        }

        return [
            ['body' => (string) ($draft['body'] ?? ''), 'when' => (string) ($draft['when'] ?? '')],
            (string) ($draft['error'] ?? ''),
        ];
    }

    private function is_ajax(array $post): bool
    {
        return ($post['action'] ?? '') === 'ajax';
    }

    /**
     * Today's log, the dashboard widget: the box, then today's entries in
     * the site zone, newest first. '' for anyone signed out, which the
     * Dashboard draws as no tile at all.
     */
    public function widget($get = [], $post = []): string
    {
        $me = $this->me();
        if ($me < 1 || !$this->links_resolved) {
            return '';
        }

        $zone  = $this->site_zone();
        $start = $this->now()->setTimezone($zone)->setTime(0, 0);
        $rows  = $this->db->select(
            'SELECT worklog_entries_id, logged_at, body FROM worklog_entries
              WHERE user_id = :me AND deleted = 0 AND logged_at >= :day_start AND logged_at < :day_end
              ORDER BY logged_at DESC, worklog_entries_id DESC
              LIMIT 50',
            ['me' => $me, 'day_start' => self::utc($start), 'day_end' => self::utc($start->modify('+1 day'))]
        );

        $entries = array_map(fn(array $row): array => $this->entry_view($row, $zone), is_array($rows) ? $rows : []);

        return Templater::fill_template($this->module_path . '/assets/templates/widget.twig', null, [
            'css'     => $this->css_url(),
            'box'     => $this->entry_form('widget'),
            'entries' => $entries,
            'count'   => count($entries),
            'log_url' => (string) ($this->links['index'] ?? '/'),
        ]);
    }

    /** One stored row as the templates print it, its time in the site zone. */
    private function entry_view(array $row, DateTimeZone $zone): array
    {
        $at    = new DateTimeImmutable((string) $row['logged_at'], new DateTimeZone('UTC'));
        $local = $at->setTimezone($zone);

        return [
            'id'         => (int) $row['worklog_entries_id'],
            'body'       => (string) $row['body'],
            'time_label' => $local->format('g:i A'),
            'datetime'   => $at->format('Y-m-d\TH:i:s\Z'),
            'day_key'    => $local->format('Y-m-d'),
        ];
    }

    /** Every live search, sort and page turn on My log. */
    public function ajax_entries_table($get = [], $post = []): string
    {
        $me = $this->me();
        if ($me < 1) {
            return $this->signed_out($post, self::SIGN_IN_TO_SEE);
        }

        return $this->entries_table($me, $post);
    }

    /** Edit, from an entry's ⋯ menu: its form, in a modal. */
    public function ajax_edit_form($get = [], $post = []): string
    {
        $me = $this->me();
        if ($me < 1) {
            return $this->signed_out($post, self::SIGN_IN_TO_SEE);
        }

        $entry = $this->own_entry($me, (int) ($post['entry_id'] ?? 0));
        if ($entry === null) {
            return self::GONE;
        }

        return $this->edit_form((int) $entry['worklog_entries_id'], [
            'body' => (string) $entry['body'],
            'when' => Entry::when_value((string) $entry['logged_at'], $this->site_zone()),
        ]);
    }

    /** Save an edit: the caller's own live entry only. ~reload, since Edit lives on My log alone. */
    public function submit_edit($get = [], $post = []): string
    {
        $me = $this->me();
        if ($me < 1) {
            return $this->signed_out($post, self::SIGN_IN_TO_SEE);
        }

        $entry_id = (int) ($post['entry_id'] ?? 0);
        if ($this->own_entry($me, $entry_id) === null) {
            return self::GONE;
        }

        $values = ['body' => (string) ($post['body'] ?? ''), 'when' => (string) ($post['when'] ?? '')];
        $check  = Entry::check($values['body'], $values['when'], $this->now(), $this->site_zone());
        if ($check['error'] !== null) {
            return $this->edit_form($entry_id, $values, $check['error']);
        }

        $saved = $this->db->update(
            'UPDATE worklog_entries SET body = :body, logged_at = :logged_at, date_updated = :updated
              WHERE worklog_entries_id = :e AND user_id = :me AND deleted = 0',
            ['body' => $check['body'], 'logged_at' => self::utc($check['at']), 'updated' => self::utc($this->now()), 'e' => $entry_id, 'me' => $me]
        );

        return $saved ? self::RELOAD_TOKEN : $this->edit_form($entry_id, $values, self::SAVE_FAILED);
    }

    /** Delete, from an entry's ⋯ menu: soft, the caller's own only; the list comes back as it was. */
    public function submit_delete($get = [], $post = []): string
    {
        $me = $this->me();
        if ($me < 1) {
            return $this->signed_out($post, self::SIGN_IN_TO_SEE);
        }

        $entry_id = (int) ($post['entry_id'] ?? 0);
        $failed   = false;
        if ($entry_id > 0) {
            $saved  = $this->db->update(
                'UPDATE worklog_entries SET deleted = 1, date_updated = :updated
                  WHERE worklog_entries_id = :e AND user_id = :me AND deleted = 0',
                ['updated' => self::utc($this->now()), 'e' => $entry_id, 'me' => $me]
            );
            $failed = !$saved;
        }

        $notice = $failed ? "<div class='alert alert-danger' role='alert'>" . self::DELETE_FAILED . '</div>' : '';

        return $notice . $this->entries_table($me, $post);
    }

    protected function entries_list(): ListView
    {
        return new ListView('WorkLog', 'ajax_entries_table', self::LIST_TARGET, ['time' => 'When'], 'time', 'desc', 50, 'entries');
    }

    /**
     * The list fragment: the caller's live entries searched (Search, over
     * the plain-text body), counted, clamped, sorted through SORTS and
     * paged, with a day row wherever the day changes.
     */
    private function entries_table(int $me, array $src): string
    {
        $list  = $this->entries_list();
        $where = ['user_id = :me', 'deleted = 0'];
        $bind  = ['me' => $me];

        [$clause, $params] = Search::where(ListView::query($src), ['body']);
        if ($clause !== '') {
            $where[] = $clause;
            $bind   += $params;
        }
        $where_sql = implode(' AND ', $where);

        $count = $this->db->select("SELECT COUNT(*) AS n FROM worklog_entries WHERE {$where_sql}", $bind, 1);
        $state = $list->state($src, (int) (is_array($count) ? ($count['n'] ?? 0) : 0));

        $column = self::SORTS[$state['sort']] ?? self::SORTS['time'];
        $dir    = $state['dir'] === 'asc' ? 'ASC' : 'DESC';
        $rows   = $this->db->select(
            "SELECT worklog_entries_id, logged_at, body FROM worklog_entries WHERE {$where_sql}
              ORDER BY {$column} {$dir}, worklog_entries_id {$dir}
              LIMIT " . (int) $state['limit'] . ' OFFSET ' . (int) $state['offset'],
            $bind
        );

        $zone    = $this->site_zone();
        $entries = [];
        $day     = null;
        foreach (is_array($rows) ? $rows : [] as $row) {
            $entry              = $this->entry_view($row, $zone);
            $entry['new_day']   = $entry['day_key'] !== $day;
            $entry['day_label'] = Entry::day_label($entry['day_key'], $this->now(), $zone);
            $day                = $entry['day_key'];
            $entries[]          = $entry;
        }

        return Templater::fill_template($this->module_path . '/assets/templates/entries-table.twig', null, [
            'entries'   => $entries,
            'controls'  => $list->controls($state),
            'carry'     => ['q' => $state['q'], 'sort' => $state['sort'], 'dir' => $state['dir'], 'page' => (string) $state['page']],
            'target'    => $list->targetdiv(),
            'searching' => Search::has_terms($state['q']),
        ]);
    }

    /** One of the caller's own live entries, or null. Never by id alone. */
    private function own_entry(int $me, int $entry_id): ?array
    {
        if ($entry_id < 1) {
            return null;
        }

        $row = $this->db->select(
            'SELECT worklog_entries_id, logged_at, body FROM worklog_entries
              WHERE worklog_entries_id = :e AND user_id = :me AND deleted = 0',
            ['e' => $entry_id, 'me' => $me],
            1
        );

        return is_array($row) && isset($row['worklog_entries_id']) ? $row : null;
    }

    private function edit_form(int $entry_id, array $values, string $error = ''): string
    {
        return Templater::fill_template($this->module_path . '/assets/templates/edit-form.twig', null, [
            'entry_id'   => $entry_id,
            'body'       => (string) ($values['body'] ?? ''),
            'when'       => (string) ($values['when'] ?? ''),
            'when_max'   => Entry::picker_max($this->now(), $this->site_zone()),
            'error'      => $error,
            'max_length' => Entry::MAX_LENGTH,
        ]);
    }

    /**
     * /m~/export?from=Y-m-d&to=Y-m-d&format=docx|csv: the caller's own
     * entries in that range, downloaded. A bad range is said on My log.
     */
    public function rout_export($get = [], $post = []): string
    {
        $me = $this->me();
        if ($me < 1) {
            return $this->page_wrapper($this->login_prompt('Sign in to export your work log.'));
        }

        $file = $this->build_export($me, $get);
        if (is_string($file)) {
            $this->core->set_message_error($file);

            return $this->rout_index($get, $post);
        }

        Export::send($file['bytes'], $file['filename'], $file['mime']);
    }

    /**
     * The export file for $me, or why not. Word unless format is csv;
     * filename work-log-{from}-{to}.
     *
     * @return array{bytes:string,filename:string,mime:string}|string
     */
    public function build_export(int $me, array $get): array|string
    {
        $zone  = $this->site_zone();
        $range = Exporter::range((string) ($get['from'] ?? ''), (string) ($get['to'] ?? ''), $this->now(), $zone);
        if (is_string($range)) {
            return $range;
        }
        [$from, $to] = $range;

        $rows = $this->db->select(
            'SELECT worklog_entries_id, logged_at, body FROM worklog_entries
              WHERE user_id = :me AND deleted = 0 AND logged_at >= :range_start AND logged_at < :range_end
              ORDER BY logged_at ASC, worklog_entries_id ASC',
            ['me' => $me, 'range_start' => self::utc($from), 'range_end' => self::utc($to->modify('+1 day'))]
        );
        $rows = is_array($rows) ? $rows : [];
        $base = 'work-log-' . $from->format('Y-m-d') . '-' . $to->format('Y-m-d');

        if (($get['format'] ?? '') === 'csv') {
            return ['bytes' => Exporter::csv($rows, $zone), 'filename' => Export::filename($base, 'csv'), 'mime' => Exporter::CSV_MIME];
        }

        return [
            'bytes'    => Export::to_docx('Work log', Exporter::docx_html($rows, $zone), [
                'Dates'   => Exporter::range_label($from, $to),
                'Entries' => (string) count($rows),
            ]),
            'filename' => Export::filename($base, 'docx'),
            'mime'     => Export::DOCX_MIME,
        ];
    }

    /** AccountHooks: a purged account's entries go with it, deleted or not. */
    public function account_purge(int $user_id): void
    {
        if ($user_id < 1) {
            return;
        }

        $this->db->delete('DELETE FROM worklog_entries WHERE user_id = :me', ['me' => $user_id]);
    }

    /**
     * AccountHooks: the account export's Work Log part, live entries only.
     *
     * @return array{entries:list<array{logged_at:string,body:string}>}
     */
    public function account_export(int $user_id): array
    {
        $rows = $this->db->select(
            'SELECT logged_at, body FROM worklog_entries
              WHERE user_id = :me AND deleted = 0
              ORDER BY logged_at ASC, worklog_entries_id ASC',
            ['me' => $user_id]
        );

        $entries = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $entries[] = [
                'logged_at' => (new DateTimeImmutable((string) $row['logged_at'], new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
                'body'      => (string) $row['body'],
            ];
        }

        return ['entries' => $entries];
    }

    /**
     * The guide for the welcome email (config welcome.guide_method). One
     * audience, so $role_names is unused. Links are absolute: a relative
     * href in an email resolves against the mail client's own document.
     */
    public function guide_html(array $role_names = []): string
    {
        return Templater::fill_template($this->module_path . '/assets/templates/guide-user.twig', null, [
            'page_url' => $this->core->full_base_web_url . $this->guide_link(),
        ]);
    }

    /**
     * The My log link for the guide, resolved without regard to roles (the
     * Meetings lesson): WelcomeEmail builds this module with the session of
     * whoever made the account, or with none at all (self-signup, cron), so
     * module_links()'s role-filtered lookup can find no page even when one
     * exists — index_link() alone would then give "/". Falls back to
     * index_link() only when no page carries this module at all.
     */
    private function guide_link(): string
    {
        $page = $this->core->get_page_link_for_module($this->module_name);
        if (!is_string($page) || $page === '') {
            return $this->index_link();
        }

        $marker = '/' . ($this->core->reserved_url_words['module_marker'] ?? 'm~');

        return str_ends_with($page, $marker) ? substr($page, 0, -strlen($marker)) : $page;
    }

    /** The signed-in person's id, or 0. The only id any statement here binds as :me. */
    protected function me(): int
    {
        return (int) ($this->core->session['user']['user_id'] ?? 0);
    }

    protected function now(): DateTimeImmutable
    {
        return $this->now_override ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /** The site's zone: "today", and a typed When, are read in it. */
    protected function site_zone(): DateTimeZone
    {
        try {
            return new DateTimeZone((string) ($this->core->config['timezone'] ?? 'America/New_York'));
        } catch (\Throwable) {
            return new DateTimeZone('America/New_York');
        }
    }

    /** An instant as logged_at, date_created and date_updated hold it. */
    private static function utc(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
