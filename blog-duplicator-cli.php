<?php

/**
 * Blog Duplicate
 */
class Blog_Duplicate extends WP_CLI_Command {

	/**
	 * Blog Duplicate
	 *
	 * ## OPTIONS
	 *
	 * [<domain-slug>]
	 * : The subdomain/directory of the new blog
	 *
	 * ## EXAMPLES
	 *
	 *     wp blog-dupe dupe
	 *     wp blog-dupe dupe domain-slug
	 */
	function dupe( $args, $assoc_args ) {

		if ( ! is_multisite() ) {
			WP_CLI::error( "This is a multisite command only." );
		}

		global $wpdb;

		// get info for origin site
		$origin_prefix = $wpdb->prefix;
		$origin_tables = $wpdb->tables('blog');
		$origin_url = home_url();
		$origin_id = get_current_blog_id();

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

		// duplicate tables
		switch_to_blog( $id );
		$url = home_url();
		foreach ( $wpdb->tables('blog') as $k => $table ) {
			$origin_table = $origin_tables[ $k ];
			$wpdb->query( "TRUNCATE TABLE $table" );
			$wpdb->query( "INSERT INTO $table SELECT * FROM $origin_table" );
		}
		update_option( 'blogname', $title );

		// rename user_roles key
		$t = $wpdb->query(
			$wpdb->prepare( "UPDATE $wpdb->options SET option_name = %s WHERE option_name = %s",
				$wpdb->base_prefix . $id . '_user_roles',
				$wpdb->base_prefix . $origin_id . '_user_roles'
			)
		);

		// upload path
		update_option( 'upload_path', "wp-content/blogs.dir/$id/files" );

		WP_CLI::run_command( array( 'search-replace', $origin_url, $url ) );
		restore_current_blog();

		WP_CLI::success( "Blog $id created." );

		$info = WP_CLI::colorize( '%BCopy over uploads to new site with (example):%n');
		WP_CLI::line( "\n$info" );
		WP_CLI::line( "cp -R  wp-content/blogs.dir/$origin_id wp-content/blogs.dir/$id\n" );

	}

}

WP_CLI::add_command( 'blog-dupe', 'Blog_Duplicate' );