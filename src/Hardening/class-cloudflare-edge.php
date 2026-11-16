<?php
namespace Codev\Simply_Static_Companion\Hardening;

use Codev\Simply_Static_Companion\Engines\Publication_Plan;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cloudflare_Edge {

	/**
	 * Prepare edge files (404.html, _headers, _redirects) inside the archive directory
	 * before the publication plan is frozen and committed.
	 *
	 * @param string           $archive_dir Raw directory where static files were generated.
	 * @param Publication_Plan $plan        The active publication plan.
	 * @return true|\WP_Error
	 */
	public static function apply( string $archive_dir, Publication_Plan $plan ) {
		$target_dir = untrailingslashit( wp_normalize_path( $archive_dir ) );
		if ( ! is_dir( $target_dir ) ) {
			return new \WP_Error( 'missing_archive_dir', 'Target directory does not exist for edge hardening.' );
		}

		self::ensure_404_html( $target_dir );
		self::generate_headers_file( $target_dir );
		self::generate_redirects_file( $target_dir );

		return true;
	}

	/**
	 * Cloudflare Pages Invariant: Prevent missing-404.html SPA fallback trap.
	 * Simply Static Free exports error pages to /404/index.html.
	 * Cloudflare Pages requires /404.html at the deployed root to prevent routing fallback to /index.html.
	 */
	public static function ensure_404_html( string $dir ): void {
		$root_404   = $dir . DIRECTORY_SEPARATOR . '404.html';
		$nested_404 = $dir . DIRECTORY_SEPARATOR . '404' . DIRECTORY_SEPARATOR . 'index.html';

		if ( file_exists( $nested_404 ) && ! file_exists( $root_404 ) ) {
			@copy( $nested_404, $root_404 );
		} elseif ( ! file_exists( $root_404 ) ) {
			// Minimal valid fallback 404 page if WordPress theme didn't export a custom 404
			$html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>404 Not Found</title><style>body{font-family:system-ui,-apple-system,sans-serif;text-align:center;padding:10vh 2rem;color:#333}h1{font-size:3rem;margin-bottom:1rem}p{color:#666}</style></head><body><h1>404</h1><p>Halaman yang Anda cari tidak ditemukan.</p><p><a href="/">Kembali ke Beranda</a></p></body></html>';
			@file_put_contents( $root_404, $html );
		}
	}

	/**
	 * Generate Cloudflare Pages `_headers` file with safe caching and security headers.
	 * Sol Review F04 Fix: Never mark mutable uploads (Elementor/Spectra dynamic CSS) as immutable!
	 */
	public static function generate_headers_file( string $dir ): void {
		$headers_file = $dir . DIRECTORY_SEPARATOR . '_headers';

		$existing_content = '';
		if ( file_exists( $headers_file ) ) {
			$existing_content = (string) file_get_contents( $headers_file );
		}

		// Standard security headers for all routes
		$rules = [];
		$rules[] = "/*\n  X-Content-Type-Options: nosniff\n  X-Frame-Options: SAMEORIGIN\n  Referrer-Policy: strict-origin-when-cross-origin";

		// Mutable assets: revalidate on deployment (Elementor post-{id}.css, Spectra assets, JS, HTML)
		$rules[] = "/*.css\n  Cache-Control: public, max-age=0, must-revalidate";
		$rules[] = "/*.js\n  Cache-Control: public, max-age=0, must-revalidate";
		$rules[] = "/*.html\n  Cache-Control: public, max-age=0, must-revalidate";

		// Fingerprinted static media files: Safe for long-term immutable edge caching (1 year)
		$media_exts = [ 'png', 'jpg', 'jpeg', 'webp', 'avif', 'svg', 'gif', 'ico', 'woff2', 'woff', 'ttf', 'mp4', 'webm' ];
		foreach ( $media_exts as $ext ) {
			$rules[] = "/*.{$ext}\n  Cache-Control: public, max-age=31536000, immutable";
		}

		$companion_rules = implode( "\n\n", $rules );

		// Preserve custom operator rules if already present in the existing _headers file
		if ( ! empty( $existing_content ) && strpos( $existing_content, 'X-Content-Type-Options' ) !== false ) {
			// Already contains headers, do not duplicate
			return;
		}

		$final_content = ! empty( $existing_content ) ? $existing_content . "\n\n" . $companion_rules : $companion_rules;
		@file_put_contents( $headers_file, $final_content );
	}

	/**
	 * Generate Cloudflare Pages `_redirects` file from WordPress redirects and Simply Static replacements.
	 */
	public static function generate_redirects_file( string $dir ): void {
		$redirects_file = $dir . DIRECTORY_SEPARATOR . '_redirects';
		$redirect_lines = [];

		// Read existing redirects if file present
		if ( file_exists( $redirects_file ) ) {
			$existing = explode( "\n", (string) file_get_contents( $redirects_file ) );
			foreach ( $existing as $line ) {
				$line = trim( $line );
				if ( ! empty( $line ) ) {
					$redirect_lines[ $line ] = true;
				}
			}
		}

		// Read offline URL replacements from Simply Static options
		$ss_options = get_option( 'simply-static', [] );
		$replacements = $ss_options['url_replacements'] ?? [];
		if ( is_array( $replacements ) ) {
			foreach ( $replacements as $replacement ) {
				$from = trim( $replacement['from'] ?? '' );
				$to   = trim( $replacement['to'] ?? '' );
				if ( ! empty( $from ) && ! empty( $to ) && $from !== $to ) {
					$from_path = wp_parse_url( $from, PHP_URL_PATH ) ?: $from;
					$to_path   = wp_parse_url( $to, PHP_URL_PATH ) ?: $to;
					if ( $from_path !== $to_path ) {
						$rule = sprintf( "%s %s 301", $from_path, $to_path );
						$redirect_lines[ $rule ] = true;
					}
				}
			}
		}

		if ( ! empty( $redirect_lines ) ) {
			@file_put_contents( $redirects_file, implode( "\n", array_keys( $redirect_lines ) ) . "\n" );
		}
	}
}
