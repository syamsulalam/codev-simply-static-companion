<?php
namespace Codev\Simply_Static_Companion\Engines;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Publication_Plan {

	public string $run_id;
	public string $mode;
	public string $archive_dir;
	public string $destination_prefix;
	public string $user;
	public string $repo;
	public string $branch;
	public ?string $base_commit_sha = null;
	public array $changed_files = [];
	public array $owned_deletions = [];
	public array $protected_paths = [
		'.github',
		'.clients',
		'.git',
		'.gitignore',
		'_routes.json',
	];

	/**
	 * Construct a validated Publication Plan.
	 */
	public function __construct( array $data ) {
		$this->run_id             = $data['run_id'] ?? uniqid( 'ss_run_', true );
		$this->mode               = $data['mode'] ?? 'full';
		$this->archive_dir        = untrailingslashit( wp_normalize_path( $data['archive_dir'] ?? '' ) );
		$this->destination_prefix = self::normalize_prefix( $data['destination_prefix'] ?? '' );
		$this->user               = trim( $data['user'] ?? '' );
		$this->repo               = trim( $data['repo'] ?? '' );
		$this->branch             = trim( $data['branch'] ?? 'main' );
		$this->base_commit_sha    = $data['base_commit_sha'] ?? null;
		$this->changed_files      = $data['changed_files'] ?? [];
		$this->owned_deletions    = $data['owned_deletions'] ?? [];

		if ( ! empty( $data['protected_paths'] ) && is_array( $data['protected_paths'] ) ) {
			$this->protected_paths = array_unique( array_merge( $this->protected_paths, $data['protected_paths'] ) );
		}
	}

	/**
	 * Build a publication plan by scanning the archive directory and computing changed files.
	 *
	 * @param string $archive_dir Raw archive directory path.
	 * @param array  $options     Simply static settings array.
	 * @param string $mode        Export mode ('full' or 'single').
	 * @return self|\WP_Error
	 */
	public static function build( string $archive_dir, array $options, string $mode = 'full' ) {
		$norm_dir = untrailingslashit( wp_normalize_path( $archive_dir ) );
		if ( empty( $norm_dir ) || ! is_dir( $norm_dir ) ) {
			return new \WP_Error( 'invalid_archive_dir', 'Archive directory does not exist or is unreadable: ' . esc_html( $norm_dir ) );
		}

		$user   = trim( $options['github_user'] ?? '' );
		$repo   = trim( $options['github_repository'] ?? '' );
		$branch = trim( $options['github_branch'] ?? 'main' );
		$prefix = trim( $options['github_folder_path'] ?? '' );

		if ( empty( $user ) || empty( $repo ) ) {
			return new \WP_Error( 'missing_target', 'GitHub user/org and repository must be configured in Simply Static settings.' );
		}

		// Inventory all files in archive directory
		$changed_files = [];
		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $norm_dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::SELF_FIRST
			);

			foreach ( $iterator as $item ) {
				if ( $item->isFile() ) {
					$full_path = wp_normalize_path( $item->getPathname() );
					$rel_path  = ltrim( substr( $full_path, strlen( $norm_dir ) ), '/' );

					// Reject traversal or control files
					if ( strpos( $rel_path, '../' ) !== false || strpos( $rel_path, '.git/' ) !== false ) {
						continue;
					}

					$changed_files[] = [
						'relative_path' => $rel_path,
						'full_path'     => $full_path,
						'size'          => $item->getSize(),
						'sha1'          => sha1_file( $full_path ),
					];
				}
			}
		} catch ( \Exception $e ) {
			return new \WP_Error( 'archive_scan_failed', 'Failed to scan archive directory: ' . $e->getMessage() );
		}

		if ( empty( $changed_files ) ) {
			return new \WP_Error( 'empty_archive', 'Archive directory contains no exportable files.' );
		}

		return new self( [
			'archive_dir'        => $norm_dir,
			'mode'               => $mode,
			'user'               => $user,
			'repo'               => $repo,
			'branch'             => $branch,
			'destination_prefix' => $prefix,
			'changed_files'      => $changed_files,
		] );
	}

	/**
	 * Normalize destination prefix (subfolder) safely.
	 * Eliminates leading/trailing slashes, path traversal, and ensures a clean POSIX relative path.
	 */
	public static function normalize_prefix( string $raw_prefix ): string {
		$prefix = trim( str_replace( '\\', '/', $raw_prefix ) );
		$prefix = trim( $prefix, '/' );

		// Prevent path traversal
		$parts = array_filter( explode( '/', $prefix ), function ( $part ) {
			return $part !== '' && $part !== '.' && $part !== '..';
		} );

		return implode( '/', $parts );
	}

	/**
	 * Resolve destination relative path for a file inside the repository.
	 */
	public function get_repo_relative_path( string $archive_relative_path ): string {
		$clean_rel = ltrim( str_replace( '\\', '/', $archive_relative_path ), '/' );
		if ( empty( $this->destination_prefix ) ) {
			return $clean_rel;
		}
		return $this->destination_prefix . '/' . $clean_rel;
	}

	/**
	 * Check if a repository-relative path is protected from deletion or overwrite.
	 */
	public function is_protected_path( string $repo_rel_path ): bool {
		$clean = ltrim( str_replace( '\\', '/', $repo_rel_path ), '/' );
		foreach ( $this->protected_paths as $protected ) {
			if ( $clean === $protected || str_starts_with( $clean, $protected . '/' ) ) {
				return true;
			}
		}
		return false;
	}
}
