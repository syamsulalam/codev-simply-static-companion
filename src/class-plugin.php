<?php
namespace Codev\Simply_Static_Companion;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plugin {

	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->register_hooks();
		$this->init_modules();
	}

	private function register_hooks(): void {
		// 1. Hook Simply Static job pipeline to inject our deployment task before wrapup
		add_filter( 'simplystatic.archive_creation_job.task_list', [ $this, 'filter_task_list' ], 20, 2 );

		// 2. Map custom task name to our implementation class
		add_filter( 'simply_static_class_name', [ $this, 'filter_task_class_name' ], 10, 2 );

		// 3. Unlock built-in GitHub admin configuration screens in Simply Static Free's React bundle
		add_filter( 'ss_settings_args', [ $this, 'filter_admin_settings_args' ], 10, 1 );

		// 4. Plugin action links
		add_filter( 'plugin_action_links_' . plugin_basename( CODEV_SS_COMPANION_FILE ), [ $this, 'filter_action_links' ] );
	}

	private function init_modules(): void {
		// Initialize Cloudflare Edge & Asset Hardening
		Hardening\Asset_Sniffer::init();

		// Initialize Selective Single-Post Auto-Export
		Features\Single_Export::init();

		// Register WP-CLI commands if running under CLI
		if ( defined( 'WP_CLI' ) && \WP_CLI ) {
			CLI\CLI_Commands::register();
		}
	}

	/**
	 * Inject 'codev_github_deploy' task right before 'wrapup'.
	 *
	 * @param array  $task_list       Array of registered task strings.
	 * @param string $delivery_method Currently selected delivery method in Simply Static.
	 * @return array Modified task list.
	 */
	public function filter_task_list( array $task_list, string $delivery_method ): array {
		if ( 'github' !== $delivery_method ) {
			return $task_list;
		}

		// Prevent duplicate insertions
		if ( in_array( 'codev_github_deploy', $task_list, true ) ) {
			return $task_list;
		}

		$wrapup_index = array_search( 'wrapup', $task_list, true );
		if ( false !== $wrapup_index ) {
			array_splice( $task_list, $wrapup_index, 0, [ 'codev_github_deploy' ] );
		} else {
			$task_list[] = 'codev_github_deploy';
			$task_list[] = 'wrapup';
		}

		return $task_list;
	}

	/**
	 * Map 'codev_github_deploy' to our implementation task class.
	 *
	 * @param string $class_name Default resolved class name.
	 * @param string $task_name  Requested task name.
	 * @return string Resolved class name.
	 */
	public function filter_task_class_name( string $class_name, string $task_name ): string {
		if ( 'codev_github_deploy' === $task_name ) {
			return 'Codev\\Simply_Static_Companion\\Tasks\\Github_Deploy_Task';
		}
		return $class_name;
	}

	/**
	 * Unlock Simply Static's React settings UI for GitHub by passing 'pro' plan argument.
	 * The React bundle (src/admin/build/index.js) renders the full GitHub tab when plan is 'pro'.
	 *
	 * @param array $args Localized script arguments.
	 * @return array Filtered arguments.
	 */
	public function filter_admin_settings_args( array $args ): array {
		$args['plan'] = 'pro';
		return $args;
	}

	/**
	 * Add shortcut link to Simply Static GitHub settings on the plugins page.
	 *
	 * @param array $links Existing action links.
	 * @return array Modified action links.
	 */
	public function filter_action_links( array $links ): array {
		$settings_url = admin_url( 'admin.php?page=simply-static_settings#github' );
		$settings_link = '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'codev-simply-static-companion' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}
}
