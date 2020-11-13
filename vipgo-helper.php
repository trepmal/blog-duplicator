<?php
function vipgo_blog_duplicator_extra_tables( $tables ) {
	$tables[] = 'a8c_cron_control_jobs';
	return $tables;
}
add_filter( 'blog_duplicator_extra_tables', 'vipgo_blog_duplicator_extra_tables', 10, 1 );
