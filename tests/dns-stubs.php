<?php
/**
 * Deterministic DNS for the unit suite.
 *
 * GitLab_API calls these unqualified from inside namespace Gitwire, so PHP
 * resolves them here before the global ones. That keeps the suite off the
 * network and lets a test assert whether a lookup happened at all.
 *
 * Excluded from PHPStan on purpose: analysing it would make these shadow the
 * real functions across the whole project and narrow their return types.
 *
 * @package Gitwire
 */

namespace Gitwire;

if ( ! function_exists( 'Gitwire\gethostbyname' ) ) {
	function gethostbyname( string $hostname ): string {
		$GLOBALS['gitwire_dns_calls'][] = 'A:' . $hostname;
		return '93.184.216.34';
	}
}

if ( ! function_exists( 'Gitwire\dns_get_record' ) ) {
	function dns_get_record( string $hostname, int $type = DNS_ANY ) {
		$GLOBALS['gitwire_dns_calls'][] = 'AAAA:' . $hostname;
		return [];
	}
}
