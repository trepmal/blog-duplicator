<?php

/**
 * Blog Duplicate
 */
class Blog_Duplicate extends WP_CLI_Command {

	/**
	 * Duplicate the current (as specifed by --url, or lack thereof) blog.
	 *
	 * ## Important!
	 *
	 * Only copies Core tables by default. Support for duplicating custom tables
	 * is handled through the `blog_duplicator_extra_tables` filter. e.g.
	 *
	 *      function myplugin_blog_duplicator_extra_tables( $tables ) {
	 *          $tables[] = 'myplugin';
	 *          return $tables;
	 *      }
	 *      add_filter( 'blog_duplicator_extra_tables', 'myplugin_blog_duplicator_extra_tables', 10, 1 );
	 *
	 * ## OPTIONS
	 *
	 * <new-blog-slug>
	 * : The subdomain/directory of the new blog. Only lowercase letters (a-z), numbers, and hyphens are allowed.
	 *
	 * [--domain]
	 * : Use if duplicated blog should have a different domain from the origin.
	 *
	 * [--skip-copy-files]
	 * : Skip copying uploaded files
	 *
	 * [--ignore-site-path]
	 * : Ignore network-defined site path
	 *
	 * [--extra-tables=<extra-tables>]
	 * : Extra tables to include in duplication. Sans-prefix, comma-separated
	 *
	 * [--verbose]
	 * : Output extra info
	 *
	 * [--yes]
	 * : Confirm 'yes' automatically
	 *
	 * ## EXAMPLES
	 *
	 *     wp duplicate domain-slug
	 *     wp duplicate test-blog-12 --url=multisite.local/test-blog-3
	 */
	public function __invoke( $args, $assoc_args ) {

		if ( ! is_multisite() ) {
			WP_CLI::error( 'This is a multisite command only.' );
		}

		list( $new_slug ) = $args;
		$domain           = WP_CLI\Utils\get_flag_value( $assoc_args, 'domain', false );
		$skip_copy_files  = WP_CLI\Utils\get_flag_value( $assoc_args, 'skip-copy-files', false );
		$ignore_site_path = WP_CLI\Utils\get_flag_value( $assoc_args, 'ignore-site-path', false );
		$manual_extra_tables = wp_parse_list( WP_CLI\Utils\get_flag_value( $assoc_args, 'extra-tables', '' ) );
		$verbose          = WP_CLI\Utils\get_flag_value( $assoc_args, 'verbose', false );

		$new_slug = trim( $new_slug );
		if ( preg_match( '|^([a-zA-Z0-9-])+$|', $new_slug ) ) {
			$new_slug = strtolower( $new_slug );
		} else {
			WP_CLI::error( 'Missing or invalid site address. Only lowercase letters (a-z), numbers, and hyphens are allowed.' );
		}

		global $wpdb;

		// Get table info for source (origin) blog.
		$extra_tables = array();

		/**
		 * Filters the list blog tables for a given blog.
		 *
		 * This filter allows new tables to be added to the core list.
		 *
		 * @param string[] $tables An array of blog tables without the database prefix.
		 */
		foreach ( apply_filters( 'blog_duplicator_extra_tables', $manual_extra_tables ) as $extra_table ) {
			$extra_tables[ $extra_table ] = $wpdb->prefix . $extra_table;
		}

		$src_tables = array_merge( $wpdb->tables( 'blog' ), $extra_tables );
		$src_url    = home_url();
		$src_roles  = get_option( $wpdb->prefix . 'user_roles');

		global $current_site;

		// Set up new blog information.
		if ( is_subdomain_install() ) {
			$dest_domain = $domain ?: $new_slug . '.' . preg_replace( '|^www\.|', '', $current_site->domain );
			$dest_path   = $ignore_site_path ? '/' : $current_site->path;
		} else {
			$dest_domain = $domain ?: $current_site->domain;
			$dest_path   = ($ignore_site_path ? '/' : $current_site->path) . $new_slug . '/';
		}

		// Additional settings to copy from origin blog.
		$dest_title = get_bloginfo() . ' Copy';
		$user_id = email_exists( get_option( 'admin_email' ) );

		WP_CLI::log( 'Preparing to create new blog:' );
		WP_CLI::log( WP_CLI::colorize( " Domain:  %G$dest_domain%n" ) );
		WP_CLI::log( WP_CLI::colorize( " Path:    %G$dest_path%n" ) );
		WP_CLI::log( WP_CLI::colorize( " Title:   %G$dest_title%n" ) );
		WP_CLI::log( WP_CLI::colorize( "Based on:   %Y$src_url%n" ) );

		WP_CLI::confirm( "Proceed with duplication?", $assoc_args );
		// First step, create the blog in the normal way.
		$new_blog_id = wpmu_create_blog( $dest_domain, $dest_path, $dest_title, $user_id, array( 'public' => 1 ), $current_site->id );

		if ( is_wp_error( $new_blog_id ) ) {
			WP_CLI::error( $new_blog_id->get_error_message() );
		}

		$this->verbose_line( 'New blog id:', $new_blog_id, $verbose );

		$src_wp_upload_dir = wp_upload_dir();
		$src_basedir       = $src_wp_upload_dir['basedir'];
		$src_baseurl       = $src_wp_upload_dir['baseurl'];

		// Switch into the new blog to duplicate tables and make other customizations.
		switch_to_blog( $new_blog_id );

		// Make upload destination.
		$dest_wp_upload_dir = wp_upload_dir();
		$dest_basedir       = $dest_wp_upload_dir['basedir'];
		$dest_baseurl       = $dest_wp_upload_dir['baseurl'];
		wp_mkdir_p( $dest_basedir );

		// Copy files.
		if ( ! $skip_copy_files ) {
			$is_shell_exec_enabled = is_callable( 'shell_exec' ) && false === stripos( ini_get( 'disable_functions' ), 'shell_exec' );

			if ( ! $is_shell_exec_enabled ) {
				WP_CLI::warning( 'shell_exec is disabled, skipping file copying!' );
			} else {
				$is_rsync_installed = ! empty( shell_exec( 'which rsync' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec

				if ( $is_rsync_installed ) {
					WP_CLI::log( 'Duplicating uploads...' );
					$this->verbose_line( 'Running command:', "rsync -a {$src_basedir}/ {$dest_basedir} --exclude sites", $verbose );
					shell_exec( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
						sprintf(
							'rsync -a %s/ %s --exclude sites',
							escapeshellarg( $src_basedir ),
							escapeshellarg( $dest_basedir )
						)
					);
				} else {
					WP_CLI::warning( 'Cannot find rsync, skipping file copying!' );
				}
			}
		} else {
			WP_CLI::warning( 'SKIPPING Duplicating uploads...' );
		}

		// Here is where table duplication starts.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$url = home_url();
		WP_CLI::log( 'Duplicating tables...' );

		// This should look familiar. We want an array of tables for the new blog that matches the table array of the source (origin).
		$extra_tables = array();
		foreach ( apply_filters( 'blog_duplicator_extra_tables', $manual_extra_tables ) as $extra_table ) {
			$extra_tables[ $extra_table ] = $wpdb->prefix . $extra_table;
		}

		$blog_tables = array_merge( $wpdb->tables( 'blog' ), $extra_tables );
		foreach ( $blog_tables as $k => $table ) {
			$src_table = $src_tables[ $k ];

			$sql = "DROP TABLE IF EXISTS $table";
			$this->verbose_line( 'Running SQL:', $sql, $verbose );
			$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			unset( $sql );

			$sql = "CREATE TABLE $table LIKE $src_table";
			$this->verbose_line( 'Running SQL:', $sql, $verbose );
			$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			unset( $sql );

			// Remove blocked options from option table before import.
			if ( $wpdb->options === $table ) {
				/**
				 * Filters the list of options that should not be copied.
				 *
				 * @param string[] $options An array of option names.
				 */
				$blocked_options = apply_filters( 'blog_duplicator_blocked_options', array( 'jetpack_options', 'jetpack_private_options', 'vaultpress' ) );

				$sql = $wpdb->prepare( "INSERT INTO $table SELECT * FROM $src_table WHERE option_name NOT IN (" . implode( ', ', array_fill( 0, count( $blocked_options ), '%s' ) ) . ')', ...$blocked_options );

			} else {
				$sql = "INSERT INTO $table SELECT * FROM $src_table";
			}

			$this->verbose_line( 'Running SQL:', $sql, $verbose );
			$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			unset( $sql );

		}

		// Re-set the blogname since the table duplication overwrote our setting in wpmu_create_blog.
		update_option( 'blogname', $dest_title );
		update_option( $wpdb->prefix . 'user_roles',  $src_roles );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Long match first, replace upload url.
		WP_CLI::log( "Run search-replace on tables (1/2)..." );
		$_command = sprintf( "search-replace '$src_baseurl' '$dest_baseurl' --url=$url --%s --all-tables-with-prefix", ( $verbose ? 'report-changed-only' : 'quiet' ) );
		$this->verbose_line( 'Running command:', $_command, $verbose );
		WP_CLI::runcommand( $_command );

		// Replace root url.
		WP_CLI::log( "Run search-replace on tables (2/2)..." );
		$_command = sprintf( "search-replace '$src_url' '$url' --url=$url --%s --all-tables-with-prefix", ( $verbose ? 'report-changed-only' : 'quiet' ) );
		$this->verbose_line( 'Running command:', $_command, $verbose );
		WP_CLI::runcommand( $_command );

		WP_CLI::runcommand( "cache flush --url=$url" );

		restore_current_blog();

		WP_CLI::success( "Blog $new_blog_id created." );

	}

	/**
	 * Outputs extra information.
	 *
	 * @param  string  $pre     Text prefix.
	 * @param  string  $text    Main text to output.
	 * @param  boolean $verbose Whether or not to output, defaults to false.
	 * @return void
	 */
	private function verbose_line( $pre, $text, $verbose = false ) {
		if ( $verbose ) {
			WP_CLI::log(
				WP_CLI::colorize(
					"%C$pre%n $text"
				)
			);
		}
	}

}

