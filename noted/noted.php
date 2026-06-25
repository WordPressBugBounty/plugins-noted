<?php

/*
Plugin Name: Noted!
Description: A simple note-taking system within the WordPress admin. Page-level and block-level notes included.
Author: Kyle Van Deusen
Version: 2.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: noted
Domain Path: /languages
*/

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('NOTED_PLUGIN_FILE', __FILE__);
define('NOTED_PLUGIN_DIR', __DIR__);

$noted_autoload = NOTED_PLUGIN_DIR . '/vendor/autoload.php';
if (file_exists($noted_autoload)) {
    require_once $noted_autoload;
}

\Noted\Plugin::instance()->init();
