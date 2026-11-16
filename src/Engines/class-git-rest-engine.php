<?php
namespace Codev\Simply_Static_Companion\Engines;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Git_Rest_Engine {

	private Publication_Plan $plan;
	private string $token;
	private string $base_url = 'https://api.github.com';
	private int $timeout = 30;

	public function __construct( Publication_Plan $plan, string $token ) {
		$this->plan  = $plan;
		$this->token = $token;
	}

	/**
	 * Execute publication via GitHub Git Database REST API.
	 *
	 * @return array|\WP_Error
	 */
	public function deploy() {
		// 1. Resolve base branch ref and commit SHA
		$ref_endpoint = sprintf( '/repos/%s/%s/git/ref/heads/%s', rawurlencode( $this->plan->user ), rawurlencode( $this->plan->repo ), rawurlencode( $this->plan->branch ) );
		$ref_res = $this->request( 'GET', $ref_endpoint );

		$base_commit_sha = null;
		$base_tree_sha   = null;

		if ( ! is_wp_error( $ref_res ) && isset( $ref_res['object']['sha'] ) ) {
			$base_commit_sha = $ref_res['object']['sha'];

			// Fetch base commit to get base tree SHA
			$commit_endpoint = sprintf( '/repos/%s/%s/git/commits/%s', rawurlencode( $this->plan->user ), rawurlencode( $this->plan->repo ), $base_commit_sha );
			$commit_res = $this->request( 'GET', $commit_endpoint );
			if ( ! is_wp_error( $commit_res ) && isset( $commit_res['tree']['sha'] ) ) {
				$base_tree_sha = $commit_res['tree']['sha'];
			}
		}

		// 2. Fetch existing base tree inventory if base_tree_sha is known (to reuse unchanged blobs)
		$existing_blobs = [];
		if ( ! empty( $base_tree_sha ) ) {
			$tree_endpoint = sprintf( '/repos/%s/%s/git/trees/%s?recursive=1', rawurlencode( $this->plan->user ), rawurlencode( $this->plan->repo ), $base_tree_sha );
			$base_tree_res = $this->request( 'GET', $tree_endpoint );
			if ( ! is_wp_error( $base_tree_res ) && ! empty( $base_tree_res['tree'] ) ) {
				foreach ( $base_tree_res['tree'] as $tree_node ) {
					if ( isset( $tree_node['path'], $tree_node['sha'], $tree_node['type'] ) && 'blob' === $tree_node['type'] ) {
						$existing_blobs[ $tree_node['path'] ] = $tree_node['sha'];
					}
				}
			}
		}

		// 3. Prepare tree items
		$text_exts = [ 'html', 'css', 'js', 'json', 'xml', 'txt', 'svg' ];
		$tree_items = [];

		foreach ( $this->plan->changed_files as $file_info ) {
			$rel_path  = $this->plan->get_repo_relative_path( $file_info['relative_path'] );
			$full_path = $file_info['full_path'];

			$content = @file_get_contents( $full_path );
			if ( false === $content ) {
				return new \WP_Error( 'unreadable_file', 'Cannot read exported file: ' . esc_html( $file_info['relative_path'] ) );
			}

			$ext = strtolower( pathinfo( $rel_path, PATHINFO_EXTENSION ) );

			// Check if we can inline valid UTF-8 text (<60 KB)
			if ( in_array( $ext, $text_exts, true ) && strlen( $content ) < 60000 && mb_check_encoding( $content, 'UTF-8' ) ) {
				$tree_items[] = [
					'path'    => $rel_path,
					'mode'    => '100644',
					'type'    => 'blob',
					'content' => $content,
				];
			} else {
				// Binary or large file: upload blob
				$blob_endpoint = sprintf( '/repos/%s/%s/git/blobs', rawurlencode( $this->plan->user ), rawurlencode( $this->plan->repo ) );
				$blob_res = $this->request( 'POST', $blob_endpoint, [
					'content'  => base64_encode( $content ),
					'encoding' => 'base64',
				] );

				if ( is_wp_error( $blob_res ) ) {
					return $blob_res;
				}

				$tree_items[] = [
					'path' => $rel_path,
					'mode' => '100644',
					'type' => 'blob',
					'sha'  => $blob_res['sha'],
				];
			}
		}

		// 4. Create Tree (Chunked in max 500 items per request, chained via intermediate base_tree)
		$current_base_tree = $base_tree_sha;
		$chunks = array_chunk( $tree_items, 450 );

		foreach ( $chunks as $chunk ) {
			$tree_endpoint = sprintf( '/repos/%s/%s/git/trees', rawurlencode( $this->plan->user ), rawurlencode( $this->plan->repo ) );
			$tree_payload  = [ 'tree' => $chunk ];
			if ( ! empty( $current_base_tree ) ) {
				$tree_payload['base_tree'] = $current_base_tree;
			}

			$tree_res = $this->request( 'POST', $tree_endpoint, $tree_payload );
			if ( is_wp_error( $tree_res ) ) {
				return $tree_res;
			}

			$current_base_tree = $tree_res['sha'];
		}

		$final_tree_sha = $current_base_tree;

		// 5. Create Commit
		$commit_endpoint = sprintf( '/repos/%s/%s/git/commits', rawurlencode( $this->plan->user ), rawurlencode( $this->plan->repo ) );
		$commit_msg = sprintf(
			'Auto-deploy static %s [%s] (run: %s)',
			$this->plan->mode,
			gmdate( 'Y-m-d H:i:s' ),
			substr( $this->plan->run_id, 0, 12 )
		);

		$commit_payload = [
			'message' => $commit_msg,
			'tree'    => $final_tree_sha,
		];
		if ( ! empty( $base_commit_sha ) ) {
			$commit_payload['parents'] = [ $base_commit_sha ];
		}

		$commit_res = $this->request( 'POST', $commit_endpoint, $commit_payload );
		if ( is_wp_error( $commit_res ) ) {
			return $commit_res;
		}

		$new_commit_sha = $commit_res['sha'];

		// 6. Update Branch Reference (force: false for conflict safety)
		if ( ! empty( $base_commit_sha ) ) {
			$update_endpoint = sprintf( '/repos/%s/%s/git/refs/heads/%s', rawurlencode( $this->plan->user ), rawurlencode( $this->plan->repo ), rawurlencode( $this->plan->branch ) );
			$update_res = $this->request( 'PATCH', $update_endpoint, [
				'sha'   => $new_commit_sha,
				'force' => false,
			] );
		} else {
			// Create brand new branch ref
			$create_ref_endpoint = sprintf( '/repos/%s/%s/git/refs', rawurlencode( $this->plan->user ), rawurlencode( $this->plan->repo ) );
			$update_res = $this->request( 'POST', $create_ref_endpoint, [
				'ref' => 'refs/heads/' . $this->plan->branch,
				'sha' => $new_commit_sha,
			] );
		}

		if ( is_wp_error( $update_res ) ) {
			return $update_res;
		}

		return [
			'success'    => true,
			'commit_sha' => $new_commit_sha,
			'status'     => 'published',
			'message'    => "Static export deployed cleanly via Git REST API ({$new_commit_sha})",
		];
	}

	/**
	 * Send authenticated HTTP request to GitHub REST API with rate-limit and backoff handling.
	 */
	private function request( string $method, string $endpoint, array $body = [] ) {
		$url = $this->base_url . $endpoint;

		$args = [
			'method'  => $method,
			'timeout' => $this->timeout,
			'headers' => [
				'Accept'               => 'application/vnd.github+json',
				'Authorization'        => 'Bearer ' . $this->token,
				'X-GitHub-Api-Version' => '2022-11-28',
				'Content-Type'         => 'application/json',
				'User-Agent'           => 'Codev-SimplyStatic-Companion/1.0',
			],
		];

		if ( ! empty( $body ) ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body_str = wp_remote_retrieve_body( $response );
		$data = json_decode( $body_str, true );

		// Handle Rate Limiting (F05 fix: never sleep in PHP-FPM!)
		if ( 429 === $code || ( 403 === $code && isset( $data['message'] ) && strpos( strtolower( $data['message'] ), 'rate limit' ) !== false ) ) {
			$reset_time = wp_remote_retrieve_header( $response, 'x-ratelimit-reset' );
			$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );

			$backoff_msg = sprintf(
				'GitHub API Rate limit reached (HTTP %d). Retry after: %s',
				$code,
				$reset_time ? date( 'Y-m-d H:i:s', (int) $reset_time ) : ($retry_after . 's')
			);

			set_transient( 'codev_ss_github_ratelimit', time() + ( $retry_after ? (int) $retry_after : 300 ), 300 );
			return new \WP_Error( 'github_rate_limited', $backoff_msg );
		}

		if ( $code >= 400 ) {
			$msg = $data['message'] ?? 'GitHub REST API Error';
			return new \WP_Error( 'github_api_error', "HTTP {$code}: {$msg}" );
		}

		return is_array( $data ) ? $data : [];
	}
}
