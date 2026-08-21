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

	public function test_repository_refresh_frequency_accepts_only_real_recurrences(): void {
		gitwire_test_set_option( 'gitwire_settings', [ 'repository_refresh_frequency' => 'daily' ] );
		$this->assertSame( 'daily', Settings::get_repository_refresh_frequency() );

		gitwire_test_set_option( 'gitwire_settings', [ 'repository_refresh_frequency' => 'fortnightly' ] );
		$this->assertSame( 'daily', Settings::get_repository_refresh_frequency() );
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

	/**
	 * @dataProvider mapped_ipv6_addresses
	 * @param string $ip IPv4-mapped or IPv4-compatible IPv6 address.
	 */
	public function test_is_safe_ip_sees_through_ipv4_mapped_ipv6( string $ip ): void {
		// PHP's range flags only handle the wrapper from 8.5. The floor is 8.1, where
		// filter_var() called ::ffff:127.0.0.1 a public address.
		$this->assertFalse( Settings::is_safe_ip( $ip ) );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public static function mapped_ipv6_addresses(): array {
		return [
			'mapped loopback'    => [ '::ffff:127.0.0.1' ],
			'mapped private 10'  => [ '::ffff:10.0.0.1' ],
			'mapped private 192' => [ '::ffff:192.168.1.1' ],
			'mapped metadata'    => [ '::ffff:169.254.169.254' ],
			'compat loopback'    => [ '::127.0.0.1' ],
			// the same addresses spelled in hex or fully expanded: the dotted form is
			// only one of several spellings, and the range flags see through none of
			// them before 8.5.
			'hex loopback'       => [ '::ffff:7f00:1' ],
			'hex metadata'       => [ '::ffff:a9fe:a9fe' ],
			'hex private 192'    => [ '::ffff:c0a8:101' ],
			'expanded loopback'  => [ '0:0:0:0:0:ffff:127.0.0.1' ],
			'compat hex'         => [ '::7f00:1' ],
		];
	}

	public function test_is_safe_ip_still_allows_a_mapped_public_address(): void {
		$this->assertTrue( Settings::is_safe_ip( '::ffff:140.82.121.4' ) );
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

	public function test_every_schema_key_appears_in_get_public_and_merge_save(): void {
		$keys = array_keys( Settings::schema() );

		// The three copies of this list are what drifted apart in #48 and #49.
		$this->assertSame( $keys, array_keys( Settings::get_public() ) );
		$this->assertSame( $keys, array_keys( Settings::merge_save( [] ) ) );
		$this->assertSame( $keys, array_keys( Settings::defaults() ) );
	}

	public function test_refresh_frequency_accepts_every_value_the_getter_allows(): void {
		foreach ( Settings::schema()['repository_refresh_frequency']['values'] as $value ) {
			$merged = Settings::merge_save( [ 'repository_refresh_frequency' => $value ] );
			$this->assertSame( $value, $merged['repository_refresh_frequency'] );

			gitwire_test_set_option( 'gitwire_settings', $merged );
			$this->assertSame( $value, Settings::get_repository_refresh_frequency() );
		}
	}

	public function test_repository_type_refresh_frequency_accepts_every_value_the_getter_allows(): void {
		foreach ( Settings::schema()['repository_type_refresh_frequency']['values'] as $value ) {
			$merged = Settings::merge_save( [ 'repository_type_refresh_frequency' => $value ] );
			$this->assertSame( $value, $merged['repository_type_refresh_frequency'] );

			gitwire_test_set_option( 'gitwire_settings', $merged );
			$this->assertSame( $value, Settings::get_repository_type_refresh_frequency() );
		}
	}

	public function test_repository_type_refresh_frequency_rejects_an_unknown_recurrence(): void {
		gitwire_test_set_option( 'gitwire_settings', [ 'repository_type_refresh_frequency' => 'hourly' ] );

		// Hourly is a real WP recurrence but not an offered value: type detection is too expensive for it.
		$this->assertSame( 'weekly', Settings::get_repository_type_refresh_frequency() );
	}

	public function test_type_detection_stays_enabled_for_smart_install_alone(): void {
		gitwire_test_set_option(
			'gitwire_settings',
			[
				'auto_detect_type' => false,
				'smart_install'    => true,
			]
		);

		// Smart Install refuses to install an undetected repo, so it needs detection alive.
		$this->assertTrue( Settings::is_type_detection_enabled() );
	}

	public function test_type_detection_is_disabled_only_when_both_switches_are_off(): void {
		gitwire_test_set_option(
			'gitwire_settings',
			[
				'auto_detect_type' => false,
				'smart_install'    => false,
			]
		);

		$this->assertFalse( Settings::is_type_detection_enabled() );
	}

	public function test_smart_install_forces_auto_detect_type_on_read(): void {
		gitwire_test_set_option(
			'gitwire_settings',
			[
				'auto_detect_type' => false,
				'smart_install'    => true,
			]
		);

		/*
		 * The browse UI reads auto_detect_type directly. Left disagreeing with
		 * is_type_detection_enabled(), it hid every badge while the server kept detecting.
		 */
		$this->assertTrue( Settings::get_public()['auto_detect_type'] );
	}

	public function test_smart_install_does_not_rewrite_auto_detect_type_once_it_is_off(): void {
		gitwire_test_set_option(
			'gitwire_settings',
			[
				'auto_detect_type' => false,
				'smart_install'    => false,
			]
		);

		$this->assertFalse( Settings::get_public()['auto_detect_type'] );
	}

	public function test_an_invalid_value_falls_back_to_the_default_not_the_loosest_option(): void {
		// log_retention_days used to fall back to 30, the most permissive choice.
		$merged = Settings::merge_save( [ 'log_retention_days' => 999 ] );

		$this->assertSame( 7, $merged['log_retention_days'] );
	}

	public function test_log_retention_is_never_zero(): void {
		gitwire_test_set_option( 'gitwire_settings', [ 'log_retention_days' => 0 ] );

		// A zero window would make trim_old_entries() delete everything before today.
		$this->assertSame( 7, Settings::get_log_retention_days() );
	}

	public function test_numeric_enum_values_survive_a_json_round_trip(): void {
		$this->assertSame( 15, Settings::merge_save( [ 'log_retention_days' => '15' ] )['log_retention_days'] );
		$this->assertSame( 30, Settings::merge_save( [ 'log_retention_days' => '30' ] )['log_retention_days'] );
	}

	/**
	 * @dataProvider blocked_gitlab_urls
	 * @param string $url URL that must be rejected.
	 */
	public function test_is_safe_remote_url_blocks_unsafe_hosts( string $url ): void {
		$this->assertFalse( Settings::is_safe_remote_url( $url ) );
	}

	public function test_is_safe_remote_url_allows_a_public_https_host(): void {
		$this->assertTrue( Settings::is_safe_remote_url( 'https://gitlab.com/archive.zip' ) );
	}

	public function test_is_safe_remote_url_rejects_empty(): void {
		// Unlike is_allowed_gitlab_url(), empty is not "use the default" here -- it is
		// a redirect target that names nothing, which must never be fetched.
		$this->assertFalse( Settings::is_safe_remote_url( '' ) );
	}
}
