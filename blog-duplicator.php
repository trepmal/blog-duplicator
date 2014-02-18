<?php

// Plugin Name: Blog Duplicator

if ( defined('WP_CLI') && WP_CLI ) {
	include plugin_dir_path( __FILE__ ) . '/blog-duplicator-cli.php';
}