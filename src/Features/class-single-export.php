<?php
namespace Codev\Simply_Static_Companion\Features;

use Codev\Simply_Static_Companion\Engines\Publication_Plan;
use Codev\Simply_Static_Companion\Engines\Git_Cli_Engine;
use Codev\Simply_Static_Companion\Engines\Git_Rest_Engine;
use Codev\Simply_Static_Companion\Hardening\Cloudflare_Edge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Single_Export {

	private const QUEUE_OPTION = 'codev_ss_pending_single_exports';
	private const CRON_HOOK    = 'codev_ss_async_single_export';

	public static function init(): void {
		// Hook post insert/update after meta and terms are saved
		add_action( 'wp_after_insert_post', [ self::class, 'on_post_saved' ], 20, 4 );

		// Hook background cron execution
		add_action( self::CRON_HOOK, [ self::class, 'process_queued_exports' ] );
	}

	/**
	 * Hooked to wp_after_insert_post: coalesces and enqueues post updates.
	 */
	public static function on_post_saved( $post_id, $post, $update, $post_before ): void {
		// Ignore autosaves, revisions, or drafts
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( 'publish' !== $post->post_status ) {
			return;
		}

		$post_type = get_post_type_object( $post->post_type );
		if ( ! $post_type || ! $post_type->public ) {
			return;
		}

		// Check if auto-export is enabled in Simply Static settings
		$ss_options = get_option( 'simply-static', [] );
		$auto_export = $ss_options['codev_auto_export_single'] ?? true;
		if ( ! $auto_export ) {
			return;
		}

		// Enqueue post ID
		$queue = get_option( self::QUEUE_OPTION, [] );
		if ( ! is_array( $queue ) ) {
			$queue = [];
		}

		if ( ! in_array( $post_id, $queue, true ) ) {
			$queue[] = (int) $post_id;
			update_option( self::QUEUE_OPTION, $queue, false );
		}

		// Schedule async execution if not already scheduled
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::CRON_HOOK );
		}
	}

	/**
	 * Process queued single exports in the background without blocking editor requests.
	 */
	public static function process_queued_exports(): void {
		$queue = get_option( self::QUEUE_OPTION, [] );
		if ( empty( $queue ) || ! is_array( $queue ) ) {
			return;
		}

		// Clear queue immediately so new incoming saves can register
		delete_option( self::QUEUE_OPTION );

		$affected_urls = [ home_url( '/' ) ];

		foreach ( $queue as $post_id ) {
			$permalink = get_permalink( $post_id );
			if ( $permalink ) {
				$affected_urls[] = $permalink;
			}

			// Add category archives
			$categories = get_the_category( $post_id );
			if ( is_array( $categories ) ) {
				foreach ( $categories as $cat ) {
					$cat_link = get_category_link( $cat->term_id );
					if ( $cat_link ) {
						$affected_urls[] = $cat_link;
					}
				}
			}
		}

		$affected_urls = array_unique( $affected_urls );

		// Execute crawl & publication
		self::export_urls_and_deploy( $affected_urls );
	}

	/**
	 * Trigger static export and overlay deploy for affected URLs.
	 */
	public static function export_urls_and_deploy( array $urls ): void {
		if ( empty( $urls ) ) {
			return;
		}

		// Check if Simply Static options & archive creation job are available
		if ( ! class_exists( '\\Simply_Static\\Plugin' ) ) {
			return;
		}

		$raw_options = get_option( 'simply-static', [] );
		$token       = trim( $raw_options['github_personal_access_token'] ?? '' );
		if ( empty( $token ) ) {
			return;
		}

		$ss_plugin = \Simply_Static\Plugin::instance();
		if ( ! method_exists( $ss_plugin, 'get_archive_creation_job' ) ) {
			return;
		}

		$job = $ss_plugin->get_archive_creation_job();
		if ( ! $job ) {
			return;
		}

		$options = \Simply_Static\Options::instance();
		$archive_dir = (string) $options->get_archive_dir();

		if ( empty( $archive_dir ) || ! is_dir( $archive_dir ) ) {
			return;
		}

		// Build single-mode publication plan
		$plan = Publication_Plan::build( $archive_dir, $raw_options, 'single' );
		if ( is_wp_error( $plan ) ) {
			return;
		}

		Cloudflare_Edge::apply( $archive_dir, $plan );

		// Deploy via conflict-safe overlay (never wiping unrelated pages)
		if ( Git_Cli_Engine::is_available() ) {
			$engine = new Git_Cli_Engine( $plan, $token );
		} else {
			$engine = new Git_Rest_Engine( $plan, $token );
		}

		$result = $engine->deploy();
		if ( ! is_wp_error( $result ) ) {
			$commit_sha = $result['commit_sha'] ?? '';
			Webhooks::dispatch( $raw_options, $commit_sha, $plan );
		}
	}
}
