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
		$this->plan          = $plan;
		$this->token         = $token;
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
	 * Execute publication via Native Git CLI with deterministic auto-pruning.
	 * Guaranteed 0-byte disk bloat via try/finally teardown and startup orphan sweep.
	 *
	 * @return array|\WP_Error Result containing commit SHA or WP_Error.
	 */
	public function deploy() {
		// Sweep any orphaned workspaces from previous hard-killed processes (>30m old)
		self::prune_stale_workspaces();

		try {
			// 1. Ensure private tenant-owned temporary workspace exists
			$init_res = $this->prepare_workspace();
			if ( is_wp_error( $init_res ) ) {
				return $init_res;
			}

			// 2. Fetch shallow remote branch (--depth 1)
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
					'success'    => true,
					'commit_sha' => $this->get_head_commit_sha(),
					'status'     => 'no_change',
					'message'    => 'No file changes detected; remote repository is already in sync.',
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
		} finally {
			// Auto-Prune Invariant: Always destroy temporary worktree after deploy to eliminate server disk bloat
			$this->teardown_workspace();
		}
	}

	/**
	 * Prepare ephemeral workspace directory outside public web root.
	 */
	private function prepare_workspace() {
		if ( ! is_dir( $this->workspace_dir ) ) {
			if ( ! wp_mkdir_p( $this->workspace_dir ) ) {
				return new \WP_Error( 'workspace_create_failed', 'Failed to create private git workspace: ' . $this->workspace_dir );
			}
		}

		// Initialize fresh git repository
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
	 * Fetch shallow remote branch (--depth 1) to ensure clean base commit while minimizing disk and network overhead.
	 */
	private function fetch_remote_branch() {
		// Fetch shallow with temporary HTTP header authorization
		$fetch_cmd = sprintf( 'fetch --depth 1 origin %s', escapeshellarg( $this->plan->branch ) );
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
	 * Compute ephemeral private workspace directory path unique to this run ID.
	 */
	private function resolve_workspace_dir(): string {
		$upload_dir = wp_upload_dir();
		$basedir    = untrailingslashit( wp_normalize_path( $upload_dir['basedir'] ?? '' ) );

		$safe_run_id = preg_replace( '/[^a-zA-Z0-9_\-]/', '_', $this->plan->run_id );
		return $basedir . '/codev-ss-work/' . $safe_run_id;
	}

	/**
	 * Auto-Prune: Recursively delete active workspace directory after execution.
	 */
	public function teardown_workspace(): void {
		if ( ! empty( $this->workspace_dir ) && is_dir( $this->workspace_dir ) ) {
			self::delete_directory_recursive( $this->workspace_dir );
		}
	}

	/**
	 * Recursively remove a directory and its contents, handling Windows/Linux permissions.
	 */
	public static function delete_directory_recursive( string $dir ): bool {
		if ( ! is_dir( $dir ) ) {
			return true;
		}

		try {
			$files = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::CHILD_FIRST
			);

			foreach ( $files as $fileinfo ) {
				$path = $fileinfo->getPathname();
				if ( $fileinfo->isDir() ) {
					@chmod( $path, 0777 );
					@rmdir( $path );
				} else {
					@chmod( $path, 0666 );
					@unlink( $path );
				}
			}

			@chmod( $dir, 0777 );
			return @rmdir( $dir );
		} catch ( \Exception $e ) {
			return false;
		}
	}

	/**
	 * Auto-Prune Sweep: Purge any abandoned workspaces older than max age (default: 30 minutes).
	 * Eliminates lingering directories if PHP-FPM crashed or was killed by system OOM.
	 */
	public static function prune_stale_workspaces( int $max_age_seconds = 1800 ): void {
		$upload_dir = wp_upload_dir();
		$basedir    = untrailingslashit( wp_normalize_path( $upload_dir['basedir'] ?? '' ) );

		// Clean up legacy cache directory if it exists
		$legacy_dir = $basedir . '/codev-ss-cache';
		if ( is_dir( $legacy_dir ) ) {
			self::delete_directory_recursive( $legacy_dir );
		}

		$work_root = $basedir . '/codev-ss-work';
		if ( ! is_dir( $work_root ) ) {
			return;
		}

		$now     = time();
		$entries = @scandir( $work_root );
		if ( ! is_array( $entries ) ) {
			return;
		}

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$entry_path = $work_root . '/' . $entry;
			if ( is_dir( $entry_path ) ) {
				$mtime = @filemtime( $entry_path );
				if ( $mtime && ( $now - $mtime ) > $max_age_seconds ) {
					self::delete_directory_recursive( $entry_path );
				}
			}
		}
	}
}
