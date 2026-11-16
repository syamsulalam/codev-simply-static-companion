<?php
namespace Codev\Simply_Static_Companion\Engines;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Git_Cli_Engine {

	private Publication_Plan $plan;
	private string $token;
	private string $workspace_dir;
	private int $timeout = 60;

	public function __construct( Publication_Plan $plan, string $token ) {
		$this->plan  = $plan;
		$this->token = $token;
		$this->workspace_dir = $this->resolve_workspace_dir();
	}

	/**
	 * Check if the host environment supports native Git CLI.
	 */
	public static function is_available(): bool {
		if ( ! function_exists( 'exec' ) ) {
			return false;
		}

		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
		if ( in_array( 'exec', $disabled, true ) ) {
			return false;
		}

		$output = [];
		$exit_code = 0;
		@exec( 'git --version 2>&1', $output, $exit_code );

		return 0 === $exit_code && ! empty( $output );
	}

	/**
	 * Execute publication via Native Git CLI.
	 *
	 * @return array|\WP_Error Result containing commit SHA or WP_Error.
	 */
	public function deploy() {
		// 1. Ensure private tenant-owned workspace exists outside public web root
		$init_res = $this->prepare_workspace();
		if ( is_wp_error( $init_res ) ) {
			return $init_res;
		}

		// 2. Fetch remote branch
		$fetch_res = $this->fetch_remote_branch();
		if ( is_wp_error( $fetch_res ) ) {
			return $fetch_res;
		}

		// 3. Overlay static archive files onto the workspace
		$overlay_res = $this->overlay_files();
		if ( is_wp_error( $overlay_res ) ) {
			return $overlay_res;
		}

		// 4. Check status and stage files
		$status = $this->exec_git( 'status --porcelain=v1' );
		if ( is_wp_error( $status ) ) {
			return $status;
		}

		if ( empty( trim( $status['output'] ) ) ) {
			// No changes detected: site is already 100% in sync
			return [
				'success'      => true,
				'commit_sha'   => $this->get_head_commit_sha(),
				'status'       => 'no_change',
				'message'      => 'No file changes detected; remote repository is already in sync.',
			];
		}

		// Stage all changes
		$add_res = $this->exec_git( 'add -A' );
		if ( is_wp_error( $add_res ) ) {
			return $add_res;
		}

		// 5. Commit with descriptive metadata
		$msg = sprintf(
			'Auto-deploy static %s [%s] (run: %s)',
			$this->plan->mode,
			gmdate( 'Y-m-d H:i:s' ),
			substr( $this->plan->run_id, 0, 12 )
		);
		$commit_res = $this->exec_git( 'commit -m ' . escapeshellarg( $msg ) );
		if ( is_wp_error( $commit_res ) ) {
			return $commit_res;
		}

		$commit_sha = $this->get_head_commit_sha();

		// 6. Push conflict-safe (fast-forward, no -f)
		$push_cmd = sprintf( 'push origin %s', escapeshellarg( $this->plan->branch ) );
		$push_res = $this->exec_git( $push_cmd );
		if ( is_wp_error( $push_res ) ) {
			return new \WP_Error(
				'git_push_failed',
				'Git push failed: ' . $push_res->get_error_message() . '. If remote advanced concurrently, pull/rebase before retrying.'
			);
		}

		return [
			'success'    => true,
			'commit_sha' => $commit_sha,
			'status'     => 'published',
			'message'    => "Static export deployed cleanly to GitHub {$this->plan->user}/{$this->plan->repo}@{$this->plan->branch} ({$commit_sha})",
		];
	}

	/**
	 * Prepare persistent workspace directory outside public web root.
	 */
	private function prepare_workspace() {
		if ( ! is_dir( $this->workspace_dir ) ) {
			if ( ! wp_mkdir_p( $this->workspace_dir ) ) {
				return new \WP_Error( 'workspace_create_failed', 'Failed to create private git workspace: ' . $this->workspace_dir );
			}
		}

		// If git repo not yet initialized in workspace
		if ( ! is_dir( $this->workspace_dir . '/.git' ) ) {
			$res = $this->exec_git( 'init -b ' . escapeshellarg( $this->plan->branch ) );
			if ( is_wp_error( $res ) ) {
				return $res;
			}

			// Configure author metadata
			$this->exec_git( 'config user.name "Codev Static Companion"' );
			$this->exec_git( 'config user.email "bot@codev.id"' );
		}

		// Ensure remote URL does NOT contain the PAT (prevent credential leakage into .git/config)
		$clean_remote_url = sprintf( 'https://github.com/%s/%s.git', rawurlencode( $this->plan->user ), rawurlencode( $this->plan->repo ) );
		$this->exec_git( 'remote remove origin' ); // ignore failure if not present
		$remote_res = $this->exec_git( 'remote add origin ' . escapeshellarg( $clean_remote_url ) );
		if ( is_wp_error( $remote_res ) ) {
			return $remote_res;
		}

		return true;
	}

	/**
	 * Fetch remote branch to ensure clean base commit and prevent repository wiping.
	 */
	private function fetch_remote_branch() {
		// Fetch with temporary HTTP header authorization
		$fetch_cmd = sprintf( 'fetch origin %s', escapeshellarg( $this->plan->branch ) );
		$fetch_res = $this->exec_git( $fetch_cmd );

		if ( ! is_wp_error( $fetch_res ) ) {
			// Remote branch exists: check it out
			$this->exec_git( 'checkout -B ' . escapeshellarg( $this->plan->branch ) . ' origin/' . escapeshellarg( $this->plan->branch ) );
		} else {
			// Brand new empty repository or branch does not exist yet: create local orphan/branch
			$this->exec_git( 'checkout -B ' . escapeshellarg( $this->plan->branch ) );
		}

		return true;
	}

	/**
	 * Overlay files from archive directory onto workspace directory.
	 */
	private function overlay_files() {
		$source_dir = $this->plan->archive_dir;
		$dest_root  = $this->workspace_dir;

		if ( ! empty( $this->plan->destination_prefix ) ) {
			$dest_root .= '/' . $this->plan->destination_prefix;
			if ( ! is_dir( $dest_root ) ) {
				wp_mkdir_p( $dest_root );
			}
		}

		foreach ( $this->plan->changed_files as $file_info ) {
			$rel_path = $file_info['relative_path'];
			$src_file = $file_info['full_path'];
			$dst_file = $dest_root . '/' . $rel_path;

			$dst_dir = dirname( $dst_file );
			if ( ! is_dir( $dst_dir ) ) {
				wp_mkdir_p( $dst_dir );
			}

			if ( ! @copy( $src_file, $dst_file ) ) {
				return new \WP_Error( 'file_overlay_failed', "Failed to overlay file: {$rel_path}" );
			}
		}

		return true;
	}

	/**
	 * Execute git command inside workspace with credential isolation and token masking.
	 */
	private function exec_git( string $subcommand ) {
		// Use -c http.extraheader to inject token in memory without writing to .git/config
		$auth_header = sprintf( 'http.extraheader=Authorization: Bearer %s', $this->token );
		$full_cmd    = sprintf(
			'git -C %s -c %s %s 2>&1',
			escapeshellarg( $this->workspace_dir ),
			escapeshellarg( $auth_header ),
			$subcommand
		);

		$output    = [];
		$exit_code = 0;
		@exec( $full_cmd, $output, $exit_code );

		$raw_out = implode( "\n", $output );
		// Sanitize token out of all output
		$clean_out = str_replace( $this->token, '***GITHUB_PAT***', $raw_out );

		if ( 0 !== $exit_code ) {
			return new \WP_Error( 'git_exec_failed', $clean_out, [ 'exit_code' => $exit_code ] );
		}

		return [
			'output'    => $clean_out,
			'exit_code' => $exit_code,
		];
	}

	private function get_head_commit_sha(): string {
		$res = $this->exec_git( 'rev-parse HEAD' );
		if ( ! is_wp_error( $res ) ) {
			return trim( $res['output'] );
		}
		return '';
	}

	/**
	 * Compute persistent private workspace directory path.
	 */
	private function resolve_workspace_dir(): string {
		$upload_dir = wp_upload_dir();
		$basedir    = untrailingslashit( wp_normalize_path( $upload_dir['basedir'] ?? '' ) );

		// Unique stable workspace per site repository target
		$repo_slug = sanitize_file_name( $this->plan->user . '_' . $this->plan->repo . '_' . $this->plan->branch );
		$cache_dir = $basedir . '/codev-ss-cache/' . $repo_slug;

		return $cache_dir;
	}
}
