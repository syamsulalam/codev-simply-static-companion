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
	/** @var resource|null */
	private $lock_fp = null;
	private ?string $lock_path = null;

	public function __construct( Publication_Plan $plan, string $token ) {
		$this->plan          = $plan;
		$this->token         = $token;
		$this->workspace_dir = $this->resolve_workspace_dir();
	}

	/**
	 * Check if the host environment supports native Git CLI with proc_open.
	 */
	public static function is_available(): bool {
		if ( ! function_exists( 'proc_open' ) || ! function_exists( 'exec' ) ) {
			return false;
		}

		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
		if ( in_array( 'proc_open', $disabled, true ) || in_array( 'exec', $disabled, true ) ) {
			return false;
		}

		$output    = [];
		$exit_code = 0;
		@exec( 'git --version 2>&1', $output, $exit_code );

		return 0 === $exit_code && ! empty( $output );
	}

	/**
	 * Compute isolated tenant work root outside public web root with 0700 permissions.
	 */
	public static function get_tenant_work_root(): string {
		$temp_dir = untrailingslashit( wp_normalize_path( sys_get_temp_dir() ) );
		if ( empty( $temp_dir ) || ! is_dir( $temp_dir ) ) {
			$temp_dir = untrailingslashit( wp_normalize_path( WP_CONTENT_DIR ) ) . '/.codev-temp';
		}

		$user = '';
		if ( function_exists( 'posix_getpwuid' ) && function_exists( 'posix_geteuid' ) ) {
			$info = posix_getpwuid( posix_geteuid() );
			$user = $info['name'] ?? '';
		}
		if ( empty( $user ) && function_exists( 'get_current_user' ) ) {
			$user = get_current_user();
		}
		$safe_user = sanitize_file_name( $user ?: 'wp' );

		$root = $temp_dir . '/codev-ss-work-' . $safe_user;
		if ( ! is_dir( $root ) ) {
			@wp_mkdir_p( $root );
			@chmod( $root, 0700 );
		}

		return $root;
	}

	/**
	 * Check whether a target path is strictly contained within an authorized work root.
	 * Defends against arbitrary directory deletion outside tenant workspace boundary.
	 */
	public static function is_path_within_authorized_root( string $dir ): bool {
		$norm_dir = untrailingslashit( wp_normalize_path( $dir ) );
		if ( empty( $norm_dir ) ) {
			return false;
		}

		$roots = [];
		$tenant_root = untrailingslashit( wp_normalize_path( self::get_tenant_work_root() ) );
		if ( ! empty( $tenant_root ) ) {
			$roots[] = $tenant_root;
		}

		$upload_dir = wp_upload_dir();
		$basedir    = untrailingslashit( wp_normalize_path( $upload_dir['basedir'] ?? '' ) );
		if ( ! empty( $basedir ) ) {
			$roots[] = $basedir . '/codev-ss-work';
			$roots[] = $basedir . '/codev-ss-cache';
		}

		foreach ( $roots as $root ) {
			if ( ! empty( $root ) && str_starts_with( $norm_dir, $root . '/' ) && $norm_dir !== $root ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Execute publication via Native Git CLI with deterministic auto-pruning.
	 * Evaluates deployment result and guarantees cleanup reporting to callers.
	 *
	 * @return array|\WP_Error Result containing commit SHA or WP_Error.
	 */
	public function deploy() {
		// Sweep any orphaned workspaces from previous hard-killed processes (>30m old)
		self::prune_stale_workspaces();

		$result = null;

		try {
			$result = $this->execute_deployment();
		} catch ( \Throwable $e ) {
			$result = new \WP_Error( 'deploy_exception', $e->getMessage() );
		} finally {
			// Auto-Prune Invariant: Always destroy temporary worktree after deploy
			$clean_ok = $this->teardown_workspace();
			if ( ! $clean_ok ) {
				if ( is_array( $result ) ) {
					$result['cleanup']  = 'pending';
					$result['warnings'] = array_merge(
						$result['warnings'] ?? [],
						[ 'Workspace directory could not be fully removed immediately; scheduled for janitor sweep.' ]
					);
				} elseif ( is_wp_error( $result ) ) {
					if ( property_exists( $result, 'data' ) ) {
						if ( ! is_array( $result->data ) ) {
							$result->data = [ 'raw_data' => $result->data ];
						}
						$result->data['cleanup'] = 'pending';
					} elseif ( method_exists( $result, 'add_data' ) ) {
						$result->add_data( [ 'cleanup' => 'pending' ] );
					}
				}
			}
		}

		return $result;
	}

	/**
	 * Internal deployment sequence executed inside the protected lifecycle boundary.
	 *
	 * @return array|\WP_Error
	 */
	private function execute_deployment() {
		// 1. Ensure private tenant-owned temporary workspace exists with advisory lock
		$init_res = $this->prepare_workspace();
		if ( is_wp_error( $init_res ) ) {
			return $init_res;
		}

		// 2. Fetch shallow remote branch (--depth 1) and bind base SHA
		$fetch_res = $this->fetch_remote_branch();
		if ( is_wp_error( $fetch_res ) ) {
			return $fetch_res;
		}

		// 3. Overlay static archive files onto the workspace with path protection
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
			$head_sha = $this->get_head_commit_sha();
			return [
				'success'    => true,
				'commit_sha' => is_string( $head_sha ) ? $head_sha : '',
				'status'     => 'no_change',
				'message'    => 'No file changes detected; remote repository is already in sync.',
			];
		}

		// Stage all changes
		$add_res = $this->exec_git( 'add -A' );
		if ( is_wp_error( $add_res ) ) {
			return $add_res;
		}

		// Extra safety: unstage .worker.lock if somehow tracked
		$this->exec_git( 'rm --cached -f .worker.lock' );

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
		if ( is_wp_error( $commit_sha ) ) {
			return $commit_sha;
		}

		// 6. Push conflict-safe (fast-forward, explicit destination ref)
		$push_cmd = sprintf( 'push origin HEAD:refs/heads/%s', escapeshellarg( $this->plan->branch ) );
		$push_res = $this->exec_git( $push_cmd );
		if ( is_wp_error( $push_res ) ) {
			return new \WP_Error(
				'git_push_failed',
				'Git push rejected: ' . $push_res->get_error_message() . '. A concurrent update may have moved remote HEAD, or permissions were denied. Please retry a fresh export.'
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
	 * Prepare ephemeral workspace directory outside public web root.
	 */
	private function prepare_workspace() {
		$root = self::get_tenant_work_root();
		if ( is_link( $root ) ) {
			return new \WP_Error( 'unsafe_work_root', 'Tenant work root cannot be a symlink: ' . $root );
		}

		if ( ! is_dir( $this->workspace_dir ) ) {
			if ( ! wp_mkdir_p( $this->workspace_dir ) ) {
				return new \WP_Error( 'workspace_create_failed', 'Failed to create private git workspace: ' . $this->workspace_dir );
			}
			@chmod( $this->workspace_dir, 0700 );
		}

		// Acquire exclusive advisory file lock on this attempt
		$this->lock_path = $this->workspace_dir . '/.worker.lock';
		$this->lock_fp   = @fopen( $this->lock_path, 'c+' );
		if ( ! $this->lock_fp || ! @flock( $this->lock_fp, LOCK_EX | LOCK_NB ) ) {
			return new \WP_Error( 'workspace_locked', 'Failed to acquire exclusive lock on deployment workspace.' );
		}

		// Initialize fresh git repository
		if ( ! is_dir( $this->workspace_dir . '/.git' ) ) {
			$res = $this->exec_git( 'init -b ' . escapeshellarg( $this->plan->branch ) );
			if ( is_wp_error( $res ) ) {
				return $res;
			}

			// Configure author metadata and deterministic newline settings
			$this->exec_git( 'config user.name "Codev Static Companion"' );
			$this->exec_git( 'config user.email "bot@codev.id"' );
			$this->exec_git( 'config core.autocrlf false' );

			// Exclude .worker.lock and any lock files from Git index so they are never staged or committed
			$exclude_file = $this->workspace_dir . '/.git/info/exclude';
			@file_put_contents( $exclude_file, "\n.worker.lock\n*.lock\n", FILE_APPEND );
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
		$branch = $this->plan->branch;

		// Validate branch ref format
		$check_res = $this->exec_git( 'check-ref-format --branch ' . escapeshellarg( $branch ) );
		if ( is_wp_error( $check_res ) ) {
			return new \WP_Error( 'invalid_branch_name', 'Invalid branch name: ' . esc_html( $branch ) );
		}

		// Attempt shallow fetch with explicit refspec
		$fetch_cmd = sprintf( 'fetch --depth 1 origin refs/heads/%1$s:refs/remotes/origin/%1$s', escapeshellarg( $branch ) );
		$fetch_res = $this->exec_git( $fetch_cmd );

		if ( ! is_wp_error( $fetch_res ) ) {
			// Remote branch exists: check it out
			$checkout_res = $this->exec_git( sprintf( 'checkout -B %1$s origin/%1$s', escapeshellarg( $branch ) ) );
			if ( is_wp_error( $checkout_res ) ) {
				return $checkout_res;
			}

			// Bind verified HEAD commit SHA to plan
			$head_sha = $this->get_head_commit_sha();
			if ( is_wp_error( $head_sha ) || empty( $head_sha ) ) {
				return new \WP_Error( 'missing_base_sha', 'Failed to retrieve HEAD commit SHA after checkout.' );
			}
			$this->plan->base_commit_sha = $head_sha;
			return true;
		}

		// Fetch failed: check whether remote repository is brand new or whether transport/auth failed
		$ls_res = $this->exec_git( sprintf( 'ls-remote --heads origin %s', escapeshellarg( $branch ) ) );
		if ( is_wp_error( $ls_res ) ) {
			// Network, credentials, or remote access failed: fail closed immediately
			return new \WP_Error(
				'git_fetch_failed',
				'Failed to fetch remote branch: ' . $fetch_res->get_error_message()
			);
		}

		$ls_output = trim( $ls_res['output'] );
		if ( ! empty( $ls_output ) ) {
			// Remote branch exists on GitHub, but fetch failed: fail closed
			return new \WP_Error(
				'git_fetch_failed',
				'Remote branch exists on GitHub but shallow fetch failed: ' . $fetch_res->get_error_message()
			);
		}

		// Remote branch does not exist yet (brand new empty repository or branch): create local branch
		$orphan_checkout = $this->exec_git( sprintf( 'checkout -B %s', escapeshellarg( $branch ) ) );
		if ( is_wp_error( $orphan_checkout ) ) {
			return $orphan_checkout;
		}
		$this->plan->base_commit_sha = null;

		return true;
	}

	/**
	 * Check whether a target path is strictly within the workspace without traversing symlinks or reparse points.
	 */
	private function is_safe_overlay_path( string $dst_file ): bool {
		$norm_dest = wp_normalize_path( $dst_file );
		$norm_work = untrailingslashit( wp_normalize_path( $this->workspace_dir ) );

		if ( ! str_starts_with( $norm_dest, $norm_work . '/' ) || $norm_dest === $norm_work ) {
			return false;
		}

		$rel_path = substr( $norm_dest, strlen( $norm_work ) + 1 );
		$segments = explode( '/', $rel_path );

		$current = $norm_work;
		for ( $i = 0; $i < count( $segments ); $i++ ) {
			$current .= '/' . $segments[ $i ];

			if ( is_link( $current ) ) {
				return false;
			}

			// Junction and reparse point check on directory ancestors
			if ( file_exists( $current ) && is_dir( $current ) ) {
				$real_dir  = realpath( $current );
				$real_work = realpath( $norm_work );
				if ( false !== $real_dir && false !== $real_work ) {
					$norm_real_dir  = wp_normalize_path( $real_dir );
					$norm_real_work = untrailingslashit( wp_normalize_path( $real_work ) );
					if ( ! str_starts_with( $norm_real_dir, $norm_real_work ) ) {
						return false; // Reparse point escaping workspace
					}
				}
			}
		}

		return true;
	}

	/**
	 * Overlay files from archive directory onto workspace directory with path protection and symlink defense.
	 */
	private function overlay_files() {
		$dest_root = $this->workspace_dir;

		// Validate destination prefix before making directories
		if ( ! empty( $this->plan->destination_prefix ) ) {
			$prefix = $this->plan->destination_prefix;
			if (
				$this->plan->is_protected_path( $prefix ) ||
				str_starts_with( $prefix, '.git' ) ||
				str_contains( $prefix, '..' ) ||
				str_starts_with( $prefix, '/' )
			) {
				return new \WP_Error( 'protected_prefix_rejected', "Destination prefix is protected or invalid: {$prefix}" );
			}

			$dest_root .= '/' . $prefix;
			if ( ! is_dir( $dest_root ) ) {
				wp_mkdir_p( $dest_root );
			}
		}

		foreach ( $this->plan->changed_files as $file_info ) {
			$rel_path = $file_info['relative_path'];
			$src_file = $file_info['full_path'];

			// Protected path enforcement: reject changes to protected paths (.clients, .github, etc.)
			if ( $this->plan->is_protected_path( $rel_path ) ) {
				continue;
			}

			// Prevent directory traversal or control path injection
			if ( strpos( $rel_path, '..' ) !== false || strpos( $rel_path, '.git/' ) !== false || str_starts_with( $rel_path, '/' ) ) {
				continue;
			}

			$repo_rel = $this->plan->get_repo_relative_path( $rel_path );
			if ( $this->plan->is_protected_path( $repo_rel ) ) {
				continue;
			}

			$dst_file = $dest_root . '/' . $rel_path;
			$dst_dir  = dirname( $dst_file );

			// Ancestor junction and symlink defense
			if ( ! $this->is_safe_overlay_path( $dst_file ) ) {
				return new \WP_Error( 'symlink_injection_detected', "Target path contains symlinks or reparse points: {$rel_path}" );
			}

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
	 * Execute git command inside workspace with credential isolation, timeout enforcement, and token masking.
	 */
	private function exec_git( string $subcommand ) {
		// Use -c http.extraheader to inject token in memory without writing to .git/config
		$auth_header = sprintf( 'http.extraheader=Authorization: Bearer %s', $this->token );
		$cmd         = sprintf(
			'git -C %s -c %s %s',
			escapeshellarg( $this->workspace_dir ),
			escapeshellarg( $auth_header ),
			$subcommand
		);

		$descriptors = [
			0 => [ 'pipe', 'r' ], // stdin
			1 => [ 'pipe', 'w' ], // stdout
			2 => [ 'pipe', 'w' ], // stderr
		];

		// Ensure inherited environment (PATH, SystemRoot, etc.) is preserved even with variables_order=GPCS
		$base_env = getenv();
		if ( ! is_array( $base_env ) || empty( $base_env ) ) {
			$base_env = $_ENV;
		}

		$env = array_merge( $base_env, [
			'GIT_TERMINAL_PROMPT' => '0',
			'GIT_ASKPASS'         => '',
		] );

		$process = @proc_open( $cmd, $descriptors, $pipes, $this->workspace_dir, $env );
		if ( ! is_resource( $process ) ) {
			return new \WP_Error( 'proc_open_failed', 'Failed to spawn Git process.' );
		}

		// Close stdin immediately to prevent blocking
		@fclose( $pipes[0] );

		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );

		$stdout_buf = '';
		$stderr_buf = '';
		$start_time = microtime( true );
		$timed_out  = false;

		while ( true ) {
			$read   = [ $pipes[1], $pipes[2] ];
			$write  = null;
			$except = null;

			$ready = @stream_select( $read, $write, $except, 0, 200000 );
			if ( false !== $ready && $ready > 0 ) {
				foreach ( $read as $pipe ) {
					$chunk = fread( $pipe, 8192 );
					if ( $pipe === $pipes[1] && strlen( $chunk ) > 0 ) {
						$stdout_buf .= $chunk;
					} elseif ( $pipe === $pipes[2] && strlen( $chunk ) > 0 ) {
						$stderr_buf .= $chunk;
					}
				}
			}

			$status = proc_get_status( $process );
			if ( ! $status['running'] ) {
				$stdout_buf .= stream_get_contents( $pipes[1] );
				$stderr_buf .= stream_get_contents( $pipes[2] );
				break;
			}

			if ( ( microtime( true ) - $start_time ) > $this->timeout ) {
				$timed_out = true;
				@proc_terminate( $process );
				usleep( 500000 );
				$term_status = proc_get_status( $process );
				if ( $term_status['running'] ) {
					@proc_terminate( $process, 9 );
				}
				break;
			}
		}

		@fclose( $pipes[1] );
		@fclose( $pipes[2] );
		$exit_code = proc_close( $process );

		$combined  = trim( $stdout_buf . ( ! empty( $stderr_buf ) ? "\n" . $stderr_buf : '' ) );
		$clean_out = str_replace( $this->token, '***GITHUB_PAT***', $combined );

		if ( $timed_out ) {
			return new \WP_Error( 'git_timeout', sprintf( 'Git process exceeded %d second timeout: %s', $this->timeout, $clean_out ) );
		}

		if ( 0 !== $exit_code ) {
			return new \WP_Error( 'git_exec_failed', $clean_out, [ 'exit_code' => $exit_code ] );
		}

		return [
			'output'    => $clean_out,
			'exit_code' => $exit_code,
		];
	}

	/**
	 * Retrieve verified HEAD commit SHA.
	 *
	 * @return string|\WP_Error
	 */
	private function get_head_commit_sha() {
		$res = $this->exec_git( 'rev-parse HEAD' );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$sha = trim( $res['output'] );
		if ( empty( $sha ) || strlen( $sha ) < 7 ) {
			return new \WP_Error( 'invalid_head_sha', 'Failed to retrieve HEAD commit SHA from Git.' );
		}
		return $sha;
	}

	/**
	 * Compute ephemeral private workspace directory path unique to this run ID.
	 * Atomically isolated child under tenant private work root with cryptographically unique attempt suffix.
	 */
	private function resolve_workspace_dir(): string {
		$work_root = self::get_tenant_work_root();

		$raw_id  = $this->plan->run_id ?? '';
		$safe_id = sanitize_file_name( preg_replace( '/[^a-zA-Z0-9_\-]/', '_', $raw_id ) );
		if ( empty( $safe_id ) ) {
			$safe_id = 'run_' . bin2hex( random_bytes( 6 ) );
		}

		// Generate cryptographically unique alphanumeric attempt ID
		$attempt_id = $safe_id . '_' . bin2hex( random_bytes( 6 ) );
		$workspace  = $work_root . '/' . $attempt_id;

		// Strict containment check: workspace must be a direct child of tenant root and never equal root
		if ( $workspace === $work_root || ! str_starts_with( $workspace, $work_root . '/' ) ) {
			throw new \RuntimeException( 'Invalid workspace path containment violation.' );
		}

		return $workspace;
	}

	/**
	 * Auto-Prune: Recursively delete active workspace directory after execution and release advisory lock.
	 *
	 * @return bool True if workspace directory was fully removed; false otherwise.
	 */
	public function teardown_workspace(): bool {
		if ( is_resource( $this->lock_fp ) ) {
			@flock( $this->lock_fp, LOCK_UN );
			@fclose( $this->lock_fp );
			$this->lock_fp = null;
		}

		if ( ! empty( $this->lock_path ) && file_exists( $this->lock_path ) ) {
			@unlink( $this->lock_path );
		}

		if ( ! empty( $this->workspace_dir ) && is_dir( $this->workspace_dir ) ) {
			self::delete_directory_recursive( $this->workspace_dir );
			return ! is_dir( $this->workspace_dir );
		}

		return true;
	}

	/**
	 * Recursively remove a directory and its contents, defending against symlinks/reparse points.
	 * Enforces path containment to prevent deletion outside authorized work roots.
	 */
	public static function delete_directory_recursive( string $dir ): bool {
		if ( ! self::is_path_within_authorized_root( $dir ) ) {
			return false;
		}

		if ( ! is_dir( $dir ) ) {
			return true;
		}

		if ( is_link( $dir ) ) {
			return @unlink( $dir ) || @rmdir( $dir );
		}

		try {
			$files = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::CHILD_FIRST
			);

			foreach ( $files as $fileinfo ) {
				$path = $fileinfo->getPathname();

				// Symlink defense: delete link itself without traversing target
				if ( $fileinfo->isLink() || is_link( $path ) ) {
					if ( ! @unlink( $path ) ) {
						@rmdir( $path );
					}
					continue;
				}

				if ( $fileinfo->isDir() ) {
					@chmod( $path, 0700 );
					@rmdir( $path );
				} else {
					@chmod( $path, 0600 );
					@unlink( $path );
				}
			}

			@chmod( $dir, 0700 );
			return @rmdir( $dir );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Auto-Prune Sweep: Purge abandoned workspaces older than max age (default: 30 minutes).
	 * Lock-aware: strictly checks advisory lock so active running workers are never deleted.
	 */
	public static function prune_stale_workspaces( int $max_age_seconds = 1800 ): void {
		$work_root = self::get_tenant_work_root();
		if ( ! is_dir( $work_root ) || is_link( $work_root ) ) {
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
			if ( is_link( $entry_path ) || ! is_dir( $entry_path ) ) {
				continue;
			}

			$mtime = @filemtime( $entry_path );
			if ( ! $mtime || ( $now - $mtime ) <= $max_age_seconds ) {
				continue; // Not stale yet
			}

			// Lock-aware check: verify attempt is genuinely abandoned by acquiring exclusive non-blocking lock
			$lock_file = $entry_path . '/.worker.lock';
			if ( file_exists( $lock_file ) ) {
				$fp = @fopen( $lock_file, 'c+' );
				if ( ! $fp ) {
					continue;
				}
				if ( ! @flock( $fp, LOCK_EX | LOCK_NB ) ) {
					// Active worker still holds lock: do not prune!
					@fclose( $fp );
					continue;
				}
				@flock( $fp, LOCK_UN );
				@fclose( $fp );
			}

			self::delete_directory_recursive( $entry_path );
		}

		// Also clean up any stale legacy directories if they exist (>30m old)
		$upload_dir = wp_upload_dir();
		$basedir    = untrailingslashit( wp_normalize_path( $upload_dir['basedir'] ?? '' ) );
		if ( ! empty( $basedir ) && is_dir( $basedir ) ) {
			foreach ( [ $basedir . '/codev-ss-work', $basedir . '/codev-ss-cache' ] as $legacy_root ) {
				if ( ! is_dir( $legacy_root ) || is_link( $legacy_root ) ) {
					continue;
				}

				$legacy_entries = @scandir( $legacy_root );
				if ( is_array( $legacy_entries ) ) {
					foreach ( $legacy_entries as $l_entry ) {
						if ( '.' === $l_entry || '..' === $l_entry ) {
							continue;
						}
						$l_path = $legacy_root . '/' . $l_entry;
						if ( is_dir( $l_path ) && ! is_link( $l_path ) ) {
							$mtime = @filemtime( $l_path );
							if ( $mtime && ( $now - $mtime ) > $max_age_seconds ) {
								self::delete_directory_recursive( $l_path );
							}
						}
					}
				}
			}
		}
	}
}
