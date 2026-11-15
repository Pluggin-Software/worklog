<?php
/**
 * Directory guard. This folder holds module code and never serves a page;
 * anything arriving here asked for the folder itself. Apache is configured
 * with indexing off, so this is the second of two locks, not the only one.
 */
header("Location: /");
exit;
