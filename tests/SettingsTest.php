<?php
/**
 * Tests for settings validation.
 *
 * @package Gitwire
 */

use Gitwire\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Covers merge_save()'s allow-lists and the SSRF host checks.
 *
 * @covers Gitwire\Settings
 */
class SettingsTest extends TestCase {

	protected function setUp(): void {
		gitwire_test_reset_options();
	}

	public function test_merge_save_keeps_stored_values_for_keys_not_sent(): void {
		gitwire_test_set_option( 'gitwire_settings', [ 'log_level' => 'error' ] );

		$merged = Settings::merge_save( [ 'smart_install' => false ] );

		$this->assertFalse( $merged['smart_install'] );
		$this->assertSame( 'error', $merged['log_level'] );
	}

	public function test_merge_save_clamps_repos_per_page(): void {
		$this->assertSame( 100, Settings::merge_save( [ 'repos_per_page' => 5000 ] )['repos_per_page'] );
		$this->assertSame( 10, Settings::merge_save( [ 'repos_per_page' => 1 ] )['repos_per_page'] );
		$this->assertSame( 50, Settings::merge_save( [ 'repos_per_page' => 50 ] )['repos_per_page'] );
	}

	public function test_merge_save_rejects_excluded_repos_that_are_not_owner_slash_name(): void {
		$merged = Settings::merge_save(
			[
				'excluded_repos' => [
					'acme/widgets',
					'not-a-repo',
					'../../etc/passwd',
					'acme/tools',
				],
			]
		);

		$this->assertSame( [ 'acme/widgets', 'acme/tools' ], $merged['excluded_repos'] );
	}

	public function test_merge_save_restricts_max_repos_per_source(): void {
		$this->assertSame( 250, Settings::merge_save( [ 'max_repos_per_source' => 250 ] )['max_repos_per_source'] );
		$this->assertSame( 'unlimited', Settings::merge_save( [ 'max_repos_per_source' => 'unlimited' ] )['max_repos_per_source'] );
		$this->assertSame( 'unlimited', Settings::merge_save( [ 'max_repos_per_source' => 999 ] )['max_repos_per_source'] );
	}

	public function test_merge_save_restricts_log_level(): void {
		$this->assertSame( 'error', Settings::merge_save( [ 'log_level' => 'error' ] )['log_level'] );
		$this->assertSame( 'activity', Settings::merge_save( [ 'log_level' => 'verbose' ] )['log_level'] );
	}

	/**
	 * Every accepted interval must be a real cron recurrence.
	 *
	 * Regression guard: 'weekly' was accepted here but missing from the recurrence
	 * map in Plugin, so picking it silently scheduled every 30 minutes instead.
	 *
	 * @dataProvider update_check_intervals
	 * @param string $interval Interval to save.
	 */
	public function test_merge_save_accepts_every_real_update_check_interval( string $interval ): void {
		$this->assertSame( $interval, Settings::merge_save( [ 'update_check_interval' => $interval ] )['update_check_interval'] );
	}

	/**
	 * @return array<int, array<int, string>>
	 */
	public static function update_check_intervals(): array {
		return [
			[ 'everyfiveminutes' ],
			[ 'halfhourly' ],
			[ 'hourly' ],
			[ 'twicedaily' ],
			[ 'daily' ],
			[ 'weekly' ],
			[ 'never' ],
		];
	}

	public function test_merge_save_falls_back_for_an_unknown_update_check_interval(): void {
		$this->assertSame( 'halfhourly', Settings::merge_save( [ 'update_check_interval' => 'yearly' ] )['update_check_interval'] );
	}

	public function test_repositories_refresh_frequency_accepts_only_real_recurrences(): void {
		gitwire_test_set_option( 'gitwire_settings', [ 'repositories_refresh_frequency' => 'daily' ] );
		$this->assertSame( 'daily', Settings::get_repositories_refresh_frequency() );

		gitwire_test_set_option( 'gitwire_settings', [ 'repositories_refresh_frequency' => 'fortnightly' ] );
		$this->assertSame( 'daily', Settings::get_repositories_refresh_frequency() );
	}

	public function test_get_public_never_leaks_unknown_keys(): void {
		gitwire_test_set_option(
			'gitwire_settings',
			[
				'smart_install' => true,
				'secret_token'  => 'should-not-appear',
			]
		);

		$this->assertArrayNotHasKey( 'secret_token', Settings::get_public() );
	}

	public function test_mask_token_hides_the_middle(): void {
		$this->assertSame( 'ghp_••••••••••••abcd', Settings::mask_token( 'ghp_xxxxxxxxxxxxabcd' ) );
		$this->assertSame( '', Settings::mask_token( '' ) );
		$this->assertSame( '••••••', Settings::mask_token( 'short1' ) );
	}

	public function test_mask_token_never_reveals_more_than_eight_characters(): void {
		$secret = 'ghp_' . str_repeat( 'S', 60 );
		$masked = Settings::mask_token( $secret );

		$this->assertStringNotContainsString( str_repeat( 'S', 9 ), $masked );
	}

	/**
	 * @dataProvider blocked_gitlab_urls
	 * @param string $url URL that must be rejected.
	 */
	public function test_is_allowed_gitlab_url_blocks_unsafe_hosts( string $url ): void {
		$this->assertFalse( Settings::is_allowed_gitlab_url( $url ) );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public static function blocked_gitlab_urls(): array {
		return [
			'plain http'             => [ 'http://gitlab.example.com' ],
			'localhost'              => [ 'https://localhost' ],
			'loopback v4'            => [ 'https://127.0.0.1' ],
			'loopback v6'            => [ 'https://[::1]' ],
			'private 10/8'           => [ 'https://10.0.0.5' ],
			'private 192.168/16'     => [ 'https://192.168.1.1' ],
			'private 172.16/12'      => [ 'https://172.16.0.1' ],
			'cloud metadata'         => [ 'https://169.254.169.254' ],
			'no scheme'              => [ 'gitlab.example.com' ],
			// Bracketed IPv6 literals used to slip past every check: parse_url keeps
			// the brackets and filter_var rejects that form, so nothing matched.
			'bracketed v6 mapped'    => [ 'https://[::ffff:127.0.0.1]' ],
			'bracketed v6 ula'       => [ 'https://[fd00::1]' ],
			'bracketed v6 linklocal' => [ 'https://[fe80::1]' ],
			'bracketed metadata'     => [ 'https://[fd00:ec2::254]' ],
			'bracketed with port'    => [ 'https://[::1]:8080' ],
		];
	}

	public function test_normalize_host_unwraps_ipv6_literals(): void {
		$this->assertSame( '::1', Settings::normalize_host( '[::1]' ) );
		$this->assertSame( 'fd00::1', Settings::normalize_host( '[FD00::1]' ) );
		$this->assertSame( 'gitlab.com', Settings::normalize_host( 'GitLab.com' ) );
		$this->assertSame( '', Settings::normalize_host( '' ) );
	}

	public function test_is_allowed_gitlab_url_allows_a_public_https_host(): void {
		$this->assertTrue( Settings::is_allowed_gitlab_url( 'https://gitlab.com' ) );
	}

	public function test_is_allowed_gitlab_url_treats_empty_as_allowed(): void {
		// Empty means "use gitlab.com", which is not a self-hosted URL to validate.
		$this->assertTrue( Settings::is_allowed_gitlab_url( '' ) );
	}

	public function test_is_safe_ip_rejects_reserved_ranges(): void {
		$this->assertFalse( Settings::is_safe_ip( '127.0.0.1' ) );
		$this->assertFalse( Settings::is_safe_ip( '::1' ) );
		$this->assertFalse( Settings::is_safe_ip( '169.254.169.254' ) );
		$this->assertFalse( Settings::is_safe_ip( 'fd00:ec2::254' ) );
		$this->assertFalse( Settings::is_safe_ip( '::ffff:127.0.0.1' ) );
		$this->assertFalse( Settings::is_safe_ip( 'not-an-ip' ) );
		$this->assertTrue( Settings::is_safe_ip( '140.82.121.4' ) );
	}
}
