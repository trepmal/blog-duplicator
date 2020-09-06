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
	 * [--verbose]
	 * : Output extra info
	 *
	 * ## EXAMPLES
	 *
	 *     wp duplicate domain-slug
	 *     wp duplicate test-site-12 --url=multisite.local/test-site-3
	 */
	function __invoke( $args, $assoc_args ) {

		if ( ! is_multisite() ) {
			WP_CLI::error( "This is a multisite command only." );
		}

		list( $new_slug ) = $args;
		$verbose = \WP_CLI\Utils\get_flag_value( $assoc_args, 'verbose', false );

		global $wpdb;

		// get info for origin site
		$origin_prefix = $wpdb->prefix;
		$schema = DB_NAME;
		$from_site_prefix_like = $wpdb->prefix;
		$sql_query = $wpdb->prepare('SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = \'%s\' AND TABLE_NAME LIKE \'%s\'', $schema, $from_site_prefix_like . '%');
		$origin_tables = $wpdb->get_col($sql_query);
		$origin_url = home_url();

		global $current_site;

		// new site information
		if ( is_subdomain_install() ) {
			$newdomain = $new_slug . '.' . preg_replace( '|^www\.|', '', $current_site->domain );
			$path      = $current_site->path;
		} else {
			$newdomain = $current_site->domain;
			$path      = $current_site->path . $new_slug . '/';
		}

		// settings to copy from origin site
		$title   = get_bloginfo() .' Copy';
		$user_id = email_exists( get_option( 'admin_email' ) );

		$this->verbose_line( "New site details:", '', $verbose );
		$this->verbose_line( '', "    domain -> $new_slug", $verbose );
		$this->verbose_line( '', "    path   -> $path", $verbose );
		$this->verbose_line( '', "    title  -> $title", $verbose );

		// first step
		$id = wpmu_create_blog( $newdomain, $path, $title, $user_id , array( 'public' => 1 ), $current_site->id );

		if ( is_wp_error( $id ) ) {
			WP_CLI::error( $id->get_error_message() );
		}

		$this->verbose_line( "New site id:", $id, $verbose );

		$src_wp_upload_dir = wp_upload_dir();
		$src_basedir = $src_wp_upload_dir['basedir'];
		$src_baseurl = $src_wp_upload_dir['baseurl'];

		// duplicate tables
		switch_to_blog( $id );
		$target_url = home_url();
			// make upload destination
			$dest_wp_upload_dir = wp_upload_dir();
			$dest_basedir = $dest_wp_upload_dir['basedir'];
			$dest_baseurl = $dest_wp_upload_dir['baseurl'];
			wp_mkdir_p( $dest_basedir );

			// copy files
			WP_CLI::line( "Duplicating uploads..." );
			$this->verbose_line( 'Running command:', "rsync -a {$src_basedir}/ {$dest_basedir} --exclude sites", $verbose );
			shell_exec( "rsync -a {$src_basedir}/ {$dest_basedir} --exclude sites" );

			// duplicate tables
			$url = home_url();
			$target_site_prefix_like = $wpdb->prefix;
			WP_CLI::line( "Duplicating tables..." );
			foreach ( $origin_tables as $k => $origin_table ) {
				$table = str_replace($from_site_prefix_like, $target_site_prefix_like, $origin_table);
				
				$wpdb->query( "DROP TABLE IF EXISTS $table" );
				$wpdb->query( "Create table $table like $origin_table" );
				$wpdb->query( "insert into $table select * from $origin_table" );
			}
			update_option( 'blogname', $title );
			update_option( 'home', $target_url );
			update_option( 'siteurl', $target_url );

			// long match first, replace upload url
			WP_CLI::line( "Search-replace'ing tables (1/2)..." );
			$_command = "search-replace '$src_baseurl' '$dest_baseurl' --url=$url --quiet";
			$this->verbose_line( 'Running command:', $_command, $verbose );
			WP_CLI::runcommand( $_command );

			// replace root url
			WP_CLI::line( "Search-replace'ing tables (2/2)..." );
			$_command = "search-replace '$origin_url' '$url' --url=$url --quiet";
			$this->verbose_line( 'Running command:', $_command, $verbose );
			WP_CLI::runcommand( $_command );

			WP_CLI::runcommand( "cache flush --url=$url" );

		restore_current_blog();

		WP_CLI::success( "Blog $id created." );

	}

	private function verbose_line( $pre, $text, $verbose=false ) {
		if ( $verbose ) {
			WP_CLI::line( WP_CLI::colorize(
				"%C$pre%n $text"
			) );
		}
	}

}

WP_CLI::add_command( 'duplicate', 'Blog_Duplicate' );
