<?php
namespace Codev\Simply_Static_Companion\Hardening;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Asset_Sniffer {

	public static function init(): void {
		// Hook Simply Static setup task's additional files filter
		add_filter( 'ss_additional_files', [ self::class, 'sniff_dynamic_assets' ], 20, 1 );
	}

	/**
	 * Sniff dynamically compiled CSS and JS files from page builders (Elementor, Spectra)
	 * and inject their absolute paths into Simply Static's asset inclusion list.
	 *
	 * @param array $files Existing additional files from settings.
	 * @return array Augmented list of files.
	 */
	public static function sniff_dynamic_assets( array $files ): array {
		$upload_dir = wp_upload_dir();
		$basedir    = untrailingslashit( wp_normalize_path( $upload_dir['basedir'] ?? '' ) );

		if ( empty( $basedir ) || ! is_dir( $basedir ) ) {
			return $files;
		}

		$sniffed = [];

		// 1. Elementor Dynamic CSS (post-{id}.css, global.css)
		$elementor_css_dir = $basedir . '/elementor/css';
		if ( is_dir( $elementor_css_dir ) ) {
			self::collect_files_by_extensions( $elementor_css_dir, [ 'css' ], $sniffed );
		}

		// 2. Spectra (Ultimate Addons for Gutenberg - UAG) Assets
		$spectra_assets_dir = $basedir . '/uag-plugin/assets';
		if ( is_dir( $spectra_assets_dir ) ) {
			self::collect_files_by_extensions( $spectra_assets_dir, [ 'css', 'js' ], $sniffed );
		}

		// 3. Astra Theme Dynamic CSS Cache
		$astra_css_dir = $basedir . '/astra-addon';
		if ( is_dir( $astra_css_dir ) ) {
			self::collect_files_by_extensions( $astra_css_dir, [ 'css' ], $sniffed );
		}

		// Merge without duplicates and preserve existing files
		foreach ( $sniffed as $sniffed_file ) {
			if ( ! in_array( $sniffed_file, $files, true ) ) {
				$files[] = $sniffed_file;
			}
		}

		return $files;
	}

	/**
	 * Helper to collect valid, readable files matching specific extensions.
	 */
	private static function collect_files_by_extensions( string $dir, array $extensions, array &$results ): void {
		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::SELF_FIRST
			);

			foreach ( $iterator as $item ) {
				if ( $item->isFile() && $item->isReadable() ) {
					$ext = strtolower( pathinfo( $item->getFilename(), PATHINFO_EXTENSION ) );
					if ( in_array( $ext, $extensions, true ) ) {
						// Security check: Ignore files > 10MB to avoid pulling in unintentional huge files
						if ( $item->getSize() < 10 * 1024 * 1024 ) {
							$results[] = wp_normalize_path( $item->getPathname() );
						}
					}
				}
			}
		} catch ( \Exception $e ) {
			// Non-blocking discovery error
		}
	}
}
