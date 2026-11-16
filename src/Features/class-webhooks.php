<?php
namespace Codev\Simply_Static_Companion\Features;

use Codev\Simply_Static_Companion\Engines\Publication_Plan;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Webhooks {

	/**
	 * Dispatch notifications and webhooks after verified publication.
	 *
	 * @param array            $options    Simply static settings.
	 * @param string           $commit_sha Published commit SHA.
	 * @param Publication_Plan $plan       Active publication plan.
	 */
	public static function dispatch( array $options, string $commit_sha, Publication_Plan $plan ): void {
		$webhook_url = trim( $options['github_webhook_url'] ?? '' );

		if ( ! empty( $webhook_url ) ) {
			self::send_webhook( $webhook_url, $commit_sha, $plan );
		}

		// Also trigger optional GitHub Repository Dispatch event if PAT is present
		$token = trim( $options['github_personal_access_token'] ?? '' );
		if ( ! empty( $token ) ) {
			self::trigger_repository_dispatch( $plan, $commit_sha, $token );
		}
	}

	/**
	 * Send authenticated webhook with SSRF defense.
	 */
	private static function send_webhook( string $url, string $commit_sha, Publication_Plan $plan ): void {
		$validated_url = self::validate_webhook_url( $url );
		if ( is_wp_error( $validated_url ) ) {
			return;
		}

		$payload = [
			'event'          => 'static_site_deployed',
			'site_url'       => home_url(),
			'site_name'      => get_bloginfo( 'name' ),
			'repository'     => $plan->user . '/' . $plan->repo,
			'branch'         => $plan->branch,
			'commit_sha'     => $commit_sha,
			'mode'           => $plan->mode,
			'timestamp'      => time(),
			'timestamp_iso'  => gmdate( 'c' ),
		];

		wp_remote_post( $validated_url, [
			'timeout'     => 15,
			'blocking'    => false, // Non-blocking dispatch
			'redirection' => 0,     // Prevent open redirect SSRF
			'headers'     => [
				'Content-Type' => 'application/json',
				'User-Agent'   => 'Codev-SimplyStatic-Companion/1.0',
			],
			'body'        => wp_json_encode( $payload ),
		] );
	}

	/**
	 * Trigger GitHub Repository Dispatch event (POST /repos/{owner}/{repo}/dispatches).
	 */
	private static function trigger_repository_dispatch( Publication_Plan $plan, string $commit_sha, string $token ): void {
		$url = sprintf( 'https://api.github.com/repos/%s/%s/dispatches', rawurlencode( $plan->user ), rawurlencode( $plan->repo ) );

		$body = [
			'event_type'     => 'static_deployed',
			'client_payload' => [
				'commit_sha' => $commit_sha,
				'branch'     => $plan->branch,
				'timestamp'  => time(),
			],
		];

		wp_remote_post( $url, [
			'timeout'  => 10,
			'blocking' => false,
			'headers'  => [
				'Accept'               => 'application/vnd.github+json',
				'Authorization'        => 'Bearer ' . $token,
				'X-GitHub-Api-Version' => '2022-11-28',
				'Content-Type'         => 'application/json',
				'User-Agent'           => 'Codev-SimplyStatic-Companion/1.0',
			],
			'body'     => wp_json_encode( $body ),
		] );
	}

	/**
	 * SSRF defense: enforce HTTPS and ensure host does not resolve to private/internal IPs.
	 */
	public static function validate_webhook_url( string $raw_url ) {
		$url = esc_url_raw( trim( $raw_url ) );
		$parsed = wp_parse_url( $url );

		if ( empty( $parsed['scheme'] ) || 'https' !== strtolower( $parsed['scheme'] ) ) {
			return new \WP_Error( 'ssrf_not_https', 'Webhook URL must use HTTPS.' );
		}

		$host = $parsed['host'] ?? '';
		if ( empty( $host ) ) {
			return new \WP_Error( 'ssrf_missing_host', 'Invalid webhook host.' );
		}

		// Resolve IP
		$ip = gethostbyname( $host );
		if ( empty( $ip ) || $ip === $host ) {
			// Failed to resolve
			return new \WP_Error( 'ssrf_dns_fail', 'Webhook host could not be resolved.' );
		}

		// Check private, loopback, and link-local ranges
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false ) {
			return new \WP_Error( 'ssrf_private_ip', 'Webhook target points to internal/private IP address.' );
		}

		return $url;
	}
}
