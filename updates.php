<?php
/**
 * Work Log's database updates. None: 1.0.0 is the first version, and its one
 * table is created from assets/install/worklog_entries.sql on the module's
 * first build (Modules::install_tables()). 1.1.0 (task hooks) added no
 * column, so it has nothing to run either. A later column goes in that file
 * AND in a migration here, keyed by its three-part version ('1.0.1').
 */
$updates = [];
