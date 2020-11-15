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
	 * <new-site-slug>
	 * : The subdomain/directory of the new blog
	 *
	 * [--skip-copy-files]
	 * : Skip copying uploaded files
	 *
	 * [--extra-tables=<extra-tables>]
	 * : Extra tables to include in duplication. Sans-prefix, comma-separated
	 *
	 * [--verbose]
	 * : Output extra info
	 *
	 * ## EXAMPLES
	 *
	 *     wp duplicate domain-slug
	 *     wp duplicate test-site-12 --url=multisite.local/test-site-3
	 */
	public function __invoke( $args, $assoc_args ) {

		if ( ! is_multisite() ) {
			WP_CLI::error( 'This is a multisite command only.' );
		}

		list( $new_slug ) = $args;
		$verbose          = WP_CLI\Utils\get_flag_value( $assoc_args, 'verbose', false );
		$skip_copy_files  = WP_CLI\Utils\get_flag_value( $assoc_args, 'skip-copy-files', false );
		$manual_extra_tables = wp_parse_list( WP_CLI\Utils\get_flag_value( $assoc_args, 'extra-tables', '' ) );

		global $wpdb;

		// Get table info for source (origin) site.
		$extra_tables = array();

		/**
		 * Filters the list blog tables for a given site.
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

		// Set up new site information.
		if ( is_subdomain_install() ) {
			$dest_domain = $new_slug . '.' . preg_replace( '|^www\.|', '', $current_site->domain );
			$dest_path   = $current_site->path;
		} else {
			$dest_domain = $current_site->domain;
			$dest_path   = $current_site->path . $new_slug . '/';
		}

		// Additional settings to copy from origin site.
		$dest_title = get_bloginfo() . ' Copy';
		$user_id = email_exists( get_option( 'admin_email' ) );

		$this->verbose_line( 'New site details:', '', $verbose );
		$this->verbose_line( '', "    domain -> $new_slug", $verbose );
		$this->verbose_line( '', "    path   -> $dest_path", $verbose );
		$this->verbose_line( '', "    title  -> $dest_title", $verbose );

		// First step, create the blog in the normal way.
		$new_site_id = wpmu_create_blog( $dest_domain, $dest_path, $dest_title, $user_id, array( 'public' => 1 ), $current_site->id );

		if ( is_wp_error( $new_site_id ) ) {
			WP_CLI::error( $new_site_id->get_error_message() );
		}

		$this->verbose_line( 'New site id:', $new_site_id, $verbose );

		$src_wp_upload_dir = wp_upload_dir();
		$src_basedir       = $src_wp_upload_dir['basedir'];
		$src_baseurl       = $src_wp_upload_dir['baseurl'];

		// Switch into the new site to duplicate tables and make other customizations.
		switch_to_blog( $new_site_id );

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
					WP_CLI::line( 'Duplicating uploads...' );
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
		WP_CLI::line( 'Duplicating tables...' );

		// This should look familiar. We want an array of tables for the new site that matches the table array of the source (origin).
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
		WP_CLI::line( "Run search-replace on tables (1/2)..." );
		$_command = "search-replace '$src_baseurl' '$dest_baseurl' --url=$url --quiet --all-tables-with-prefix";
		$this->verbose_line( 'Running command:', $_command, $verbose );
		WP_CLI::runcommand( $_command );

		// Replace root url.
		WP_CLI::line( "Run search-replace on tables (2/2)..." );
		$_command = "search-replace '$src_url' '$url' --url=$url --quiet --all-tables-with-prefix";
		$this->verbose_line( 'Running command:', $_command, $verbose );
		WP_CLI::runcommand( $_command );

		WP_CLI::runcommand( "cache flush --url=$url" );

		restore_current_blog();

		WP_CLI::success( "Blog $new_site_id created." );

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
			WP_CLI::line(
				WP_CLI::colorize(
					"%C$pre%n $text"
				)
			);
		}
	}

}

