<?php
/**
 * Provides deterministic DNS stubs for the test suite.
 *
 * GitLab_API calls these functions from the Gitwire namespace, so PHP resolves
 * these definitions before the global functions. This keeps tests off the network
 * and allows them to verify whether a lookup occurs.
 *
 * PHPStan excludes this file because these definitions would shadow the global
 * functions across the project and narrow their return types.
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
