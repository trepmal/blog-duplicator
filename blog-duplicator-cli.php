<?php

/**
 * Blog Duplicate
 */
class Blog_Duplicate extends WP_CLI_Command {

	/**
	 * Duplicate the current blog.
	 *
	 * ## OPTIONS
	 *
	 * <new-site-slug>
	 * : The subdomain/directory of the new blog
	 *
	 * [--skip-copy-files]
	 * : Skip copying uploaded files
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
		$verbose          = \WP_CLI\Utils\get_flag_value( $assoc_args, 'verbose', false );
		$skip_copy_files  = \WP_CLI\Utils\get_flag_value( $assoc_args, 'skip-copy-files', false );

		global $wpdb;

		// get table info for origin site.
		$extra_tables = array();

		/**
		 * Filters the list blog tables for a given site.
		 *
		 * This filter allows new tables to be added to the core list.
		 *
		 * @param string[] $tables An array of blog tables without the database prefix.
		 */
		foreach ( apply_filters( 'blog_duplicator_extra_tables', array() ) as $extra_table ) {
			$extra_tables[ $extra_table ] = $wpdb->prefix . $extra_table;
		}

		$origin_tables  = array_merge( $wpdb->tables( 'blog' ), $extra_tables );
		$origin_url     = home_url();
		$original_roles = get_option( $wpdb->prefix . 'user_roles');

		global $current_site;

		// new site information.
		if ( is_subdomain_install() ) {
			$newdomain = $new_slug . '.' . preg_replace( '|^www\.|', '', $current_site->domain );
			$path      = $current_site->path;
		} else {
			$newdomain = $current_site->domain;
			$path      = $current_site->path . $new_slug . '/';
		}

		// settings to copy from origin site.
		$title   = get_bloginfo() . ' Copy';
		$user_id = email_exists( get_option( 'admin_email' ) );

		$this->verbose_line( 'New site details:', '', $verbose );
		$this->verbose_line( '', "    domain -> $new_slug", $verbose );
		$this->verbose_line( '', "    path   -> $path", $verbose );
		$this->verbose_line( '', "    title  -> $title", $verbose );

		// first step.
		$id = wpmu_create_blog( $newdomain, $path, $title, $user_id, array( 'public' => 1 ), $current_site->id );

		if ( is_wp_error( $id ) ) {
			WP_CLI::error( $id->get_error_message() );
		}

		$this->verbose_line( 'New site id:', $id, $verbose );

		$src_wp_upload_dir = wp_upload_dir();
		$src_basedir       = $src_wp_upload_dir['basedir'];
		$src_baseurl       = $src_wp_upload_dir['baseurl'];

		// duplicate tables.
		switch_to_blog( $id );

		// make upload destination.
		$dest_wp_upload_dir = wp_upload_dir();
		$dest_basedir       = $dest_wp_upload_dir['basedir'];
		$dest_baseurl       = $dest_wp_upload_dir['baseurl'];
		wp_mkdir_p( $dest_basedir );

		// copy files.
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

		// duplicate tables.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$url = home_url();
		WP_CLI::line( 'Duplicating tables...' );

		$extra_tables = array();
		foreach ( apply_filters( 'blog_duplicator_extra_tables', array() ) as $extra_table ) {
			$extra_tables[ $extra_table ] = $wpdb->prefix . $extra_table;
		}

		$blog_tables = array_merge( $extra_tables, $wpdb->tables( 'blog' ) );
		foreach ( $blog_tables as $k => $table ) {
			$origin_table = $origin_tables[ $k ];

			$this->verbose_line( 'Running SQL:', "TRUNCATE TABLE $table", $verbose );
			$wpdb->query( "TRUNCATE TABLE $table" );

			// Remove blocked options from option table before import.
			if ( $wpdb->options === $table ) {
				/**
				 * Filters the list of options that should not be copied.
				 *
				 * @param string[] $options An array of option names.
				 */
				$blocked_options = apply_filters( 'blog_duplicator_blocked_options', array( 'jetpack_options', 'jetpack_private_options', 'vaultpress' ) );

				$sql = $wpdb->prepare( "INSERT INTO $table SELECT * FROM $origin_table WHERE option_name NOT IN (" . implode( ', ', array_fill( 0, count( $blocked_options ), '%s' ) ) . ')', ...$blocked_options );
			} else {
				$sql = "INSERT INTO $table SELECT * FROM $origin_table";
			}

			$this->verbose_line( 'Running SQL:', $sql, $verbose );
			$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		update_option( 'blogname', $title );
		update_option( $wpdb->prefix . 'user_roles',  $original_roles );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// long match first, replace upload url.
		WP_CLI::line( "Search-replace'ing tables (1/2)..." );
		$_command = "search-replace '$src_baseurl' '$dest_baseurl' --url=$url --quiet --all-tables-with-prefix";
		$this->verbose_line( 'Running command:', $_command, $verbose );
		WP_CLI::runcommand( $_command );

		// replace root url.
		WP_CLI::line( "Search-replace'ing tables (2/2)..." );
		$_command = "search-replace '$origin_url' '$url' --url=$url --quiet --all-tables-with-prefix";
		$this->verbose_line( 'Running command:', $_command, $verbose );
		WP_CLI::runcommand( $_command );

		WP_CLI::runcommand( "cache flush --url=$url" );

		restore_current_blog();

		WP_CLI::success( "Blog $id created." );

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

WP_CLI::add_command( 'duplicate', 'Blog_Duplicate' );
