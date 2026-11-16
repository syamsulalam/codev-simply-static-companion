<?php
namespace Codev\Simply_Static_Companion\CLI;

use Simply_Static\Plugin as SS_Plugin;
use Simply_Static\Options as SS_Options;
use Codev\Simply_Static_Companion\Features\Single_Export;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLI_Commands {

	public static function register(): void {
		if ( class_exists( '\\WP_CLI' ) ) {
			\WP_CLI::add_command( 'simply-static-codev', self::class );
		}
	}

	/**
	 * Run a full static site export and immediately deploy to GitHub.
	 *
	 * ## OPTIONS
	 *
	 * [--engine=<git|rest>]
	 * : Deployment engine to use. Default is 'git' (or autodetect).
	 *
	 * [--branch=<branch>]
	 * : Target GitHub branch override. Default is 'main'.
	 *
	 * [--timeout=<seconds>]
	 * : Maximum wait timeout in seconds. Default is 300.
	 *
	 * ## EXAMPLES
	 *
	 *     wp simply-static-codev export-and-deploy
	 *     wp simply-static-codev export-and-deploy --engine=git --branch=production
	 *
	 * @when after_wp_load
	 */
	public function export_and_deploy( $args, $assoc_args ) {
		\WP_CLI::line( '=== Codev Simply Static Export & Deploy ===' );

		if ( ! class_exists( '\\Simply_Static\\Plugin' ) ) {
			\WP_CLI::error( 'Simply Static Free plugin is not active on this site.' );
		}

		$options = SS_Options::instance();
		$orig_delivery = $options->get( 'delivery_method' );

		// Set delivery method to 'github'
		$options->set( 'delivery_method', 'github' )->save();

		// Handle branch override if passed
		$branch_override = $assoc_args['branch'] ?? '';
		if ( ! empty( $branch_override ) ) {
			$raw_options = get_option( 'simply-static', [] );
			$raw_options['github_branch'] = sanitize_text_field( $branch_override );
			update_option( 'simply-static', $raw_options );
		}

		// F06 fix: Access job runner via public accessor
		$plugin_instance = SS_Plugin::instance();
		if ( ! method_exists( $plugin_instance, 'get_archive_creation_job' ) ) {
			\WP_CLI::error( 'Method get_archive_creation_job() is unavailable on Simply Static Plugin instance.' );
		}

		$job = $plugin_instance->get_archive_creation_job();
		if ( ! $job ) {
			\WP_CLI::error( 'Simply Static Archive Creation Job could not be retrieved.' );
		}

		\WP_CLI::line( 'Starting static export archive job...' );
		$started = $job->start();
		if ( false === $started || is_wp_error( $started ) ) {
			$msg = is_wp_error( $started ) ? $started->get_error_message() : 'start() returned false';
			\WP_CLI::error( "Failed to start export job: {$msg}" );
		}

		$timeout_secs = isset( $assoc_args['timeout'] ) ? (int) $assoc_args['timeout'] : 300;
		$deadline = time() + $timeout_secs;

		// Monitor progress
		while ( ! $job->is_job_done() ) {
			if ( time() > $deadline ) {
				\WP_CLI::error( "Export job timed out after {$timeout_secs} seconds." );
			}

			$current_task = method_exists( $job, 'get_current_task' ) ? $job->get_current_task() : 'processing';
			\WP_CLI::line( sprintf( '[%s] Running task: %s', gmdate( 'H:i:s' ), $current_task ?: 'initiating' ) );
			sleep( 2 );
		}

		// F06 fix: Verify terminal result from persisted option; do not assume is_job_done() equals success
		$job_option = get_option( 'simply-static-archive-creation-job', [] );
		if ( ! empty( $job_option['error'] ) ) {
			\WP_CLI::error( sprintf( 'Export failed with error: %s', $job_option['error'] ) );
		}

		\WP_CLI::success( 'Static export and GitHub deployment completed successfully with zero errors.' );
	}

	/**
	 * Run a selective single post/page export and deploy.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : The ID of the post or page to export.
	 *
	 * ## EXAMPLES
	 *
	 *     wp simply-static-codev single 42
	 *
	 * @when after_wp_load
	 */
	public function single( $args, $assoc_args ) {
		$post_id = (int) ( $args[0] ?? 0 );
		if ( ! $post_id || ! get_post( $post_id ) ) {
			\WP_CLI::error( "Invalid Post ID: {$post_id}" );
		}

		$permalink = get_permalink( $post_id );
		\WP_CLI::line( sprintf( "Exporting single post #%d (%s)...", $post_id, $permalink ) );

		Single_Export::export_urls_and_deploy( [ $permalink, home_url( '/' ) ] );

		\WP_CLI::success( "Single post #{$post_id} export triggered successfully." );
	}

	/**
	 * Inspect Simply Static companion status, active engine, and git configuration.
	 *
	 * @when after_wp_load
	 */
	public function status( $args, $assoc_args ) {
		\WP_CLI::line( '=== Simply Static Companion Status ===' );

		$git_avail = \Codev\Simply_Static_Companion\Engines\Git_Cli_Engine::is_available();
		\WP_CLI::line( 'Native Git CLI Available: ' . ( $git_avail ? 'YES' : 'NO (REST Fallback only)' ) );

		$options = get_option( 'simply-static', [] );
		$user    = $options['github_user'] ?? '(not set)';
		$repo    = $options['github_repository'] ?? '(not set)';
		$branch  = $options['github_branch'] ?? 'main';
		$prefix  = $options['github_folder_path'] ?? '(root)';

		\WP_CLI::line( "GitHub Target: {$user}/{$repo}@{$branch} [Folder: {$prefix}]" );

		$has_token = ! empty( $options['github_personal_access_token'] );
		\WP_CLI::line( 'GitHub PAT Configured: ' . ( $has_token ? 'YES (Masked)' : 'NO' ) );

		\WP_CLI::success( 'Status check completed.' );
	}
}
