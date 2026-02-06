<?php

namespace FluentBoards\Database;

use FluentBoards\Database\Migrations\AttachmentMigrator;
use FluentBoards\Database\Migrations\BoardMigrator;
use FluentBoards\Database\Migrations\BoardTermMigrator;
use FluentBoards\Database\Migrations\CommentsMigrator;
use FluentBoards\Database\Migrations\TaskMigrator;
use FluentBoards\Database\Migrations\TaskMetaMigrator;
use FluentBoards\Database\Migrations\NotificationMigrator;
use FluentBoards\Database\Migrations\NotificationUserMigrator;
use FluentBoards\Database\Migrations\MetaMigrator;
use FluentBoards\Database\Migrations\ActivityMigrator;
use FluentBoards\Database\Migrations\RelationMigrator;
use FluentBoards\Database\Migrations\TeamMigrator;

class DBMigrator
{
	public static function run($network_wide = false)
	{
		global $wpdb;

		if ( $network_wide ) {
			// Retrieve all site IDs from this network (WordPress >= 4.6 provides easy to use functions for that).
			if ( function_exists( 'get_sites' ) && function_exists( 'get_current_network_id' ) ) {
				$site_ids = get_sites( array( 'fields' => 'ids', 'network_id' => get_current_network_id() ) );
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Retrieving site IDs for multisite migration
				$site_ids = $wpdb->get_col( "SELECT blog_id FROM $wpdb->blogs WHERE site_id = $wpdb->siteid;" );
			}
			// Install the plugin for all these sites.
			foreach ( $site_ids as $site_id ) {
				switch_to_blog( $site_id );
				self::migrate(false);
				restore_current_blog();
			}
		}  else {
			self::migrate(false);
		}
	}

	public static function migrate($isForced = false)
	{
		BoardMigrator::migrate($isForced);
		BoardTermMigrator::migrate($isForced);
		TaskMigrator::migrate($isForced);
		TaskMetaMigrator::migrate($isForced);
		NotificationMigrator::migrate($isForced);
        NotificationUserMigrator::migrate($isForced);
        MetaMigrator::migrate($isForced);
        ActivityMigrator::migrate($isForced);
        RelationMigrator::migrate($isForced);
        CommentsMigrator::migrate($isForced);
        AttachmentMigrator::migrate($isForced);
        TeamMigrator::migrate($isForced);
	}

	public static function handle_new_site($new_site)
	{
		if (!is_plugin_active_for_network('fluent-boards/fluent-boards.php')) {
			return;
		}

		switch_to_blog($new_site->blog_id);
		self::migrate(false);
		restore_current_blog();
	}
}
