<?php
if (!defined('WP_UNINSTALL_PLUGIN')) exit;

global $wpdb;
$table_name = $wpdb->prefix . 'wcsp_push_logs';
$wpdb->query("DROP TABLE IF EXISTS $table_name");
