<?php
/**
 * Activity logger — appends timestamped entries to a protected file in uploads.
 *
 * @package Gitwire
 * @since 1.3.0
 */

namespace Gitwire;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes and reads plugin activity to an append-only log file.
 */
class Logger {

	/**
	 * Absolute path to the log file.
	 *
	 * @var string
	 */
	private string $log_file;

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Returns the singleton, bootstrapping the log directory on first call.
	 *
	 * @since 1.3.0
	 * @return self
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Resolves the log file path and ensures the directory exists.
	 */
	private function __construct() {
		$upload_dir     = wp_upload_dir();
		$dir            = $upload_dir['basedir'] . '/gitwire-logs';
		$this->log_file = $dir . '/activity.log';
		$this->ensure_dir( $dir );
	}

	/**
	 * Appends a timestamped entry when logging is enabled. No-op otherwise.
	 *
	 * @since 1.3.0
	 * @param string $message Human-readable description of the activity.
	 * @return void
	 */
	public static function log( string $message ): void {
		if ( ! Settings::is_logging_enabled() ) {
			return;
		}
		self::get_instance()->write( $message );
	}

	/**
	 * Returns log file contents with newest entries first.
	 *
	 * @since 1.3.0
	 * @return string
	 */
	public function get_contents(): string {
		if ( ! file_exists( $this->log_file ) ) {
			return '';
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$contents = file_get_contents( $this->log_file );
		if ( ! is_string( $contents ) || '' === $contents ) {
			return '';
		}
		$lines = array_filter( explode( PHP_EOL, trim( $contents ) ) );
		return implode( PHP_EOL, array_reverse( array_values( $lines ) ) );
	}

	/**
	 * Empties the log file.
	 *
	 * @since 1.3.0
	 * @return bool True on success.
	 */
	public function clear(): bool {
		if ( ! file_exists( $this->log_file ) ) {
			return true;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		return false !== file_put_contents( $this->log_file, '' );
	}

	/**
	 * Appends a formatted line to the log file.
	 *
	 * @since 1.3.0
	 * @param string $message Log message.
	 * @return void
	 */
	private function write( string $message ): void {
		$line = '[' . gmdate( 'Y-m-d H:i:s' ) . '] ' . $message . PHP_EOL;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $this->log_file, $line, FILE_APPEND | LOCK_EX );
	}

	/**
	 * Creates the log directory and blocks direct web access via .htaccess.
	 *
	 * @since 1.3.0
	 * @param string $dir Absolute path to the log directory.
	 * @return void
	 */
	private function ensure_dir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $htaccess, 'Deny from all' );
		}
	}
}
