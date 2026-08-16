<?php
/**
 * Blocks direct web access to plugin-owned directories.
 *
 * @package Gitwire
 * @since 1.0.0
 */

namespace Gitwire;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes the guard files that keep a directory unreachable over HTTP.
 */
class Filesystem_Guard {

	/**
	 * Guard filenames written into every protected directory.
	 *
	 * Callers that sweep a protected directory must keep these.
	 *
	 * @var string[]
	 */
	public const GUARD_FILES = [ '.htaccess', 'index.php', 'index.html', 'web.config' ];

	/**
	 * Writes deny rules for Apache, IIS, and a directory-listing stub.
	 *
	 * Nginx honours none of these, which is why callers also keep the filename
	 * itself unguessable rather than relying on this alone.
	 *
	 * @since 1.0.0
	 * @param string $dir Absolute path to an existing directory.
	 * @return void
	 */
	public static function protect_directory( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$files = [
			'.htaccess'  => "# Apache 2.4\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n\n# Apache 2.2\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n",
			'index.php'  => "<?php\n// Silence is golden.\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n\t<system.webServer>\n\t\t<authorization>\n\t\t\t<deny users=\"*\" />\n\t\t</authorization>\n\t</system.webServer>\n</configuration>\n",
		];

		foreach ( $files as $name => $contents ) {
			$path = trailingslashit( $dir ) . $name;
			if ( file_exists( $path ) ) {
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $path, $contents );
		}
	}
}
