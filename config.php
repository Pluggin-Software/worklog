<?php

declare(strict_types=0);

/*
 * Work Log 1.0.0 (spec docs/superpowers/specs/2026-11-13-worklog-design.md):
 * "what I just did", a few seconds to write, private to the person who
 * wrote it. 1.1.0: a task you tick done is logged too (core 2.98.0 task
 * hooks).
 */
$config = [
    'module_machine_name' => 'es_worklog',
    'version_number'      => '1.1.0',
    // templates render with Twig autoescape on (Templater::declares_autoescape())
    'autoescape'          => true,
    'module_name'         => 'Work Log',
    'author'              => 'Kevin Bruce',
    // My log's stylesheet. The widget and the + modal link the same file
    // themselves: neither the dashboard nor an arbitrary page loads it.
    'css'                 => ['styles.css'],
    'js'                  => [],
    // A brand-new module creates this from assets/install/ on its first
    // build (Modules::install_tables()), so updates.php has nothing to run.
    'db_tables'           => ['worklog_entries'],
    // No role of its own: anyone signed in keeps their own log, and no role,
    // an Admin's or a Super User's included, reads anyone else's.
    'roles'               => [],
    // The welcome email (estuaries\WelcomeEmail). Work Log owns no role, so
    // core_roles names the core role that qualifies a recipient: 3,
    // Authenticated User, everyone with an account (Meetings' and Tasks'
    // shape). url is empty on purpose: the template prints it into an href,
    // and the guide opens with the absolute address instead.
    'welcome'             => [
        'title'        => 'Work Log',
        'blurb'        => 'Jot down what you just did in a few seconds, and find it again later. Only you can see it.',
        'url'          => '',
        'core_roles'   => [3],
        'guide_method' => 'guide_html',
    ],
    // The dashboard widget (docs/architecture/dashboard-widgets.md).
    // use_class and the page are resolved at scan time; the module's first
    // build registers it and puts it on the site's default layout.
    'widget'              => [
        'title'  => "Today's log",
        'method' => 'widget',
    ],
    // The header's + (CoreQuicklinks, core 2.65.0). 'index' makes the page
    // itself the link without JavaScript; 'modal' opens the box in place
    // (es_ajax_modal). Shown to whoever the module's page admits.
    'quick_actions'       => [
        'index' => [
            'label' => 'Log what I just did',
            'icon'  => 'fa-clipboard-list',
            'group' => 'create',
            'modal' => ['useclass' => 'WorkLog', 'usemethod' => 'ajax_entry_form'],
        ],
        // The page itself, under Go to; shown wherever the + entry is (a page
        // carrying Work Log that this person's roles open).
        'mylog' => [
            'label' => 'My log',
            'icon'  => 'fa-clipboard-list',
            'group' => 'go',
            'path'  => '',
        ],
    ],
    // A purged account takes its private entries with it; an account export
    // carries them (estuaries\AccountHooks, docs/architecture/signup-and-closure.md).
    // Called with a bare user id, never through PostRouter.
    'account_hooks'       => [
        'purge'  => 'account_purge',
        'export' => 'account_export',
    ],
    // core 2.98.0 task hooks: a person ticking a task done logs it here too.
    // Never a request, so task_done() is not in REQUEST_METHODS; only
    // estuaries\TaskHooks::call() reaches it, and only from CoreTasks' own
    // person paths — never a module closing its own bookkeeping task.
    'task_hooks'          => [
        'done' => 'task_done',
    ],
];
