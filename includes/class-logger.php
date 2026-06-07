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
	 * Appends a timestamped entry when logging is enabled and the level meets the minimum.
	 *
	 * @since 1.3.0
	 * @param string $message Human-readable description of the activity.
	 * @param string $level   'activity' or 'error'.
	 * @return void
	 */
	public static function log( string $message, string $level = 'activity' ): void {
		if ( ! Settings::is_logging_enabled() ) {
			return;
		}
		// 'error' minimum level silences non-error entries.
		if ( 'error' === Settings::get_log_level() && 'error' !== $level ) {
			return;
		}
		self::get_instance()->write( $message, $level );
	}

	/**
	 * Returns log entries as structured arrays, newest first, with optional filters.
	 *
	 * @since 1.3.0
	 * @param string $from  ISO date string 'YYYY-MM-DD' or empty for no lower bound.
	 * @param string $to    ISO date string 'YYYY-MM-DD' or empty for no upper bound.
	 * @param string $level Level to keep ('activity', 'error'), or empty for all.
	 * @return array<int, array{timestamp: string, level: string, message: string}>
	 */
	public function get_entries( string $from = '', string $to = '', string $level = '' ): array {
		if ( ! file_exists( $this->log_file ) ) {
			return [];
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$contents = file_get_contents( $this->log_file );
		if ( ! is_string( $contents ) || '' === trim( $contents ) ) {
			return [];
		}

		$entries = [];
		foreach ( array_filter( explode( PHP_EOL, trim( $contents ) ) ) as $line ) {
			$entry = self::parse_line( $line );
			if ( null === $entry ) {
				continue;
			}
			$date = substr( $entry['timestamp'], 0, 10 );
			if ( '' !== $from && $date < $from ) {
				continue;
			}
			if ( '' !== $to && $date > $to ) {
				continue;
			}
			if ( '' !== $level && $entry['level'] !== $level ) {
				continue;
			}
			$entries[] = $entry;
		}

		return array_reverse( $entries );
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
	 * Appends a formatted line to the log file, then trims old entries if retention is set.
	 *
	 * @since 1.3.0
	 * @param string $message Log message.
	 * @param string $level   Log level.
	 * @return void
	 */
	private function write( string $message, string $level ): void {
		$user       = wp_get_current_user();
		$actor_part = $user->exists() ? ' [@' . $user->user_login . ']' : '';
		$line       = '[' . gmdate( 'Y-m-d H:i:s' ) . '] [' . $level . ']' . $actor_part . ' ' . $message . PHP_EOL;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $this->log_file, $line, FILE_APPEND | LOCK_EX );
	}

	/**
	 * Removes entries older than the configured retention window, at most once per day.
	 * Called from the maintenance cron — not triggered on every write.
	 *
	 * @since 1.3.0
	 * @return void
	 */
	public function trim_old_entries(): void {
		$days = Settings::get_log_retention_days();
		if ( 0 === $days || get_transient( 'gitwire_log_trim' ) ) {
			return;
		}
		set_transient( 'gitwire_log_trim', 1, DAY_IN_SECONDS );

		if ( ! file_exists( $this->log_file ) ) {
			return;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$contents = file_get_contents( $this->log_file );
		if ( ! is_string( $contents ) ) {
			return;
		}

		$cutoff = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
		$kept   = [];
		foreach ( array_filter( explode( PHP_EOL, trim( $contents ) ) ) as $line ) {
			$entry = self::parse_line( $line );
			if ( null === $entry || substr( $entry['timestamp'], 0, 10 ) >= $cutoff ) {
				$kept[] = $line;
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $this->log_file, $kept ? implode( PHP_EOL, $kept ) . PHP_EOL : '', LOCK_EX );
	}

	/**
	 * Parses a single log line into a structured entry, or null if the format is unrecognized.
	 *
	 * @since 1.3.0
	 * @param string $line Raw log line.
	 * @return array{timestamp: string, level: string, actor: string, message: string}|null
	 */
	private static function parse_line( string $line ): ?array {
		// Current format: [timestamp] [level] [@actor] message
		if ( preg_match( '/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] \[(activity|error)\] \[(@[^\]]+)\] (.+)$/', $line, $m ) ) {
			return [ 'timestamp' => $m[1], 'level' => $m[2], 'actor' => $m[3], 'message' => $m[4] ];
		}
		// Legacy format: [timestamp] [level] message (actor may be @login prefix in message)
		if ( preg_match( '/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] \[(activity|error)\] (.+)$/', $line, $m ) ) {
			$actor   = '';
			$message = $m[3];
			if ( preg_match( '/^(@\S+) (.+)$/', $message, $am ) ) {
				$actor   = $am[1];
				$message = $am[2];
			}
			return [ 'timestamp' => $m[1], 'level' => $m[2], 'actor' => $actor, 'message' => $message ];
		}
		return null;
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
