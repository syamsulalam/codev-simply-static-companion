<?php
namespace Codev\Simply_Static_Companion\Tasks;

use Simply_Static\Task;
use Simply_Static\Util;
use Codev\Simply_Static_Companion\Engines\Publication_Plan;
use Codev\Simply_Static_Companion\Engines\Git_Cli_Engine;
use Codev\Simply_Static_Companion\Engines\Git_Rest_Engine;
use Codev\Simply_Static_Companion\Hardening\Cloudflare_Edge;
use Codev\Simply_Static_Companion\Features\Webhooks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Github_Deploy_Task extends Task {

	/**
	 * Unique task identifier matched in archive job pipeline.
	 */
	protected static $task_name = 'codev_github_deploy';

	/**
	 * Resolved archive directory containing flat static files.
	 */
	private string $archive_dir = '';

	public function __construct() {
		parent::__construct();
		// F01 fix: Reuse inherited parent $this->options (declared protected in Simply_Static\Task)
		if ( isset( $this->options ) && method_exists( $this->options, 'get_archive_dir' ) ) {
			$this->archive_dir = (string) $this->options->get_archive_dir();
		}
	}

	/**
	 * Perform GitHub deployment task.
	 *
	 * F02 fix: Contract: returns true on verified complete publication,
	 * false on resumable slice, or \WP_Error on terminal failure.
	 *
	 * @return bool|\WP_Error
	 */
	public function perform() {
		$raw_options = get_option( 'simply-static', [] );

		$user   = trim( $raw_options['github_user'] ?? '' );
		$repo   = trim( $raw_options['github_repository'] ?? '' );
		$token  = trim( $raw_options['github_personal_access_token'] ?? '' );
		$branch = trim( $raw_options['github_branch'] ?? 'main' );

		// Check credentials
		if ( empty( $user ) || empty( $repo ) || empty( $token ) ) {
			$err = new \WP_Error(
				'missing_credentials',
				__( 'GitHub Deployment skipped: Missing User, Repository, or Personal Access Token in Simply Static settings.', 'codev-simply-static-companion' )
			);
			$this->save_status_message( $err->get_error_message(), 'error' );
			return $err; // Halt archive creation job with error state
		}

		if ( empty( $this->archive_dir ) || ! is_dir( $this->archive_dir ) ) {
			$err = new \WP_Error(
				'missing_archive_dir',
				sprintf( __( 'Static archive directory does not exist or is unreadable: %s', 'codev-simply-static-companion' ), esc_html( $this->archive_dir ) )
			);
			$this->save_status_message( $err->get_error_message(), 'error' );
			return $err;
		}

		$this->save_status_message(
			sprintf( __( 'Deploying static files to GitHub: %s/%s (branch: %s)...', 'codev-simply-static-companion' ), $user, $repo, $branch )
		);

		// 1. Build unified publication plan
		$plan = Publication_Plan::build( $this->archive_dir, $raw_options, 'full' );
		if ( is_wp_error( $plan ) ) {
			$this->save_status_message( $plan->get_error_message(), 'error' );
			return $plan;
		}

		// 2. Apply Cloudflare Edge Hardening (404.html, safe _headers, _redirects)
		$edge_res = Cloudflare_Edge::apply( $this->archive_dir, $plan );
		if ( is_wp_error( $edge_res ) ) {
			$this->save_status_message( $edge_res->get_error_message(), 'error' );
			return $edge_res;
		}

		// Re-scan plan so edge files are included in the transfer inventory
		$plan = Publication_Plan::build( $this->archive_dir, $raw_options, 'full' );
		if ( is_wp_error( $plan ) ) {
			$this->save_status_message( $plan->get_error_message(), 'error' );
			return $plan;
		}

		// 3. Select transport engine: Native Git CLI (Primary) vs REST Database API (Fallback)
		if ( Git_Cli_Engine::is_available() ) {
			$engine = new Git_Cli_Engine( $plan, $token );
			$this->save_status_message( __( 'Using Native Git CLI transport (zero-quota atomic diffing)...', 'codev-simply-static-companion' ) );
		} else {
			$engine = new Git_Rest_Engine( $plan, $token );
			$this->save_status_message( __( 'Using Git REST Database API fallback transport...', 'codev-simply-static-companion' ) );
		}

		// 4. Execute deployment
		$result = $engine->deploy();
		if ( is_wp_error( $result ) ) {
			$this->save_status_message(
				sprintf( __( 'GitHub Deployment failed: %s', 'codev-simply-static-companion' ), $result->get_error_message() ),
				'error'
			);
			return $result; // Halts job runner and records error state
		}

		$commit_sha = $result['commit_sha'] ?? '';
		$this->save_status_message(
			sprintf( __( 'GitHub Deployment succeeded! Commit SHA: %s', 'codev-simply-static-companion' ), $commit_sha )
		);

		// 5. Post-commit verified webhooks and notifications
		Webhooks::dispatch( $raw_options, $commit_sha, $plan );

		return true; // Verified complete; advances archive creation job to wrapup
	}
}
