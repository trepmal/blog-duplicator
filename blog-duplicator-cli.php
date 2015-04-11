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
	 * [<domain-slug>]
	 * : The subdomain/directory of the new blog
	 *
	 * ## EXAMPLES
	 *
	 *     wp duplicate
	 *     wp duplicate domain-slug
	 */
	function __invoke( $args, $assoc_args ) {

		if ( ! is_multisite() ) {
			WP_CLI::error( "This is a multisite command only." );
		}

		global $wpdb;

		// get info for origin site
		$origin_prefix = $wpdb->prefix;
		$origin_tables = $wpdb->tables('blog');
		$origin_url = home_url();

		global $current_site;

 		if ( ! isset( $args[0] ) ) {

			// prepare question
			if ( is_subdomain_install() ) {
	 			$question = '________.' . preg_replace( '|^www\.|', '', $current_site->domain );
	 		} else {
	 			$question = $current_site->domain . $current_site->path . '________';
	 		}

	 		$colorize = WP_CLI::colorize( '%Upress ctrl-D when done%n');
	 		$blank = WP_CLI::colorize( "%G$question%n");
			WP_CLI::line( "Provide a site address\nand $colorize\nFill in the blank: $blank" );

			$domain = trim( WP_CLI::get_value_from_arg_or_stdin( false, false ) );
			$newaddress = str_replace( '________', $domain, $question );
			WP_CLI::line( "\nNew site address: $newaddress" );

		} else {
			$domain = $args[0];
		}

		// new site information
		if ( is_subdomain_install() ) {
			$newdomain = $domain . '.' . preg_replace( '|^www\.|', '', $current_site->domain );
			$path      = $current_site->path;
		} else {
			$newdomain = $current_site->domain;
			$path      = $current_site->path . $domain . '/';
		}

		// settings to copy from origin site
		$title = get_bloginfo() .' Copy';
		$user_id = email_exists( get_option( 'admin_email' ) );

		// first step
		$id = wpmu_create_blog( $newdomain, $path, $title, $user_id , array( 'public' => 1 ), $current_site->id );

		if ( is_wp_error( $id ) ) {
			WP_CLI::error( $id->get_error_message() );
		}

		WP_CLI::line( "Duplicating tables..." );

		$src_wp_upload_dir = wp_upload_dir();
		$src_basedir = $src_wp_upload_dir['basedir'];
		$src_baseurl = $src_wp_upload_dir['baseurl'];

		// duplicate tables
		switch_to_blog( $id );
			// make upload destination
			$dest_wp_upload_dir = wp_upload_dir();
			$dest_basedir = $dest_wp_upload_dir['basedir'];
			$dest_baseurl = $dest_wp_upload_dir['baseurl'];
			wp_mkdir_p( $dest_basedir );

			// copy files
			shell_exec( "rsync -a {$src_basedir}/ {$dest_basedir} --exclude sites" );

			// duplicate tables
			$url = home_url();
			foreach ( $wpdb->tables('blog') as $k => $table ) {
				$origin_table = $origin_tables[ $k ];
				$wpdb->query( "TRUNCATE TABLE $table" );
				$wpdb->query( "INSERT INTO $table SELECT * FROM $origin_table" );
			}
			update_option( 'blogname', $title );

			// long match first, replace upload url
			WP_CLI::run_command( array( 'search-replace', $src_baseurl, $dest_baseurl ) );
			// replace root url
			WP_CLI::run_command( array( 'search-replace', $origin_url, $url ) );

		restore_current_blog();

		WP_CLI::success( "Blog $id created." );

	}

}

WP_CLI::add_command( 'duplicate', 'Blog_Duplicate' );