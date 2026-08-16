<?php
/**
 * Activity logger — appends timestamped entries to a protected file in uploads.
 *
 * @package Gitwire
 * @since 1.0.0
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
	 * @since 1.0.0
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
	 *
	 * Lives in uploads rather than the system temp dir: /tmp is shared with every
	 * other account on the box, and PrivateTmp/tmpwatch wipe it out from under us.
	 * The filename is salt-derived and the directory is blocked from web access.
	 */
	private function __construct() {
		$uploads        = function_exists( 'wp_upload_dir' ) ? wp_upload_dir( null, false ) : [];
		$base           = ! empty( $uploads['basedir'] ) ? $uploads['basedir'] : WP_CONTENT_DIR . '/uploads';
		$dir            = trailingslashit( $base ) . 'gitwire-logs';
		$hash           = substr( hash( 'sha256', wp_salt( 'auth' ) . 'gitwire-log' ), 0, 32 );
		$this->log_file = $dir . '/' . $hash . '.log';
		$this->ensure_dir( $dir );
	}

	/**
	 * Appends a timestamped entry when logging is enabled and the level meets the minimum.
	 *
	 * @since 1.0.0
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
	 * @since 1.0.0
	 * @param string   $from   ISO date string 'YYYY-MM-DD' or empty for no lower bound.
	 * @param string   $to     ISO date string 'YYYY-MM-DD' or empty for no upper bound.
	 * @param string   $level  Level to keep ('activity', 'error'), or empty for all.
	 * @param string[] $actors User logins to include; empty means all actors.
	 * @return array<int, array{timestamp: string, level: string, message: string}>
	 */
	public function get_entries( string $from = '', string $to = '', string $level = '', array $actors = [] ): array {
		if ( ! file_exists( $this->log_file ) ) {
			return [];
		}

		try {
			$file = new \SplFileObject( $this->log_file, 'r' );
		} catch ( \RuntimeException $e ) {
			return [];
		}
		$file->setFlags( \SplFileObject::READ_AHEAD | \SplFileObject::SKIP_EMPTY | \SplFileObject::DROP_NEW_LINE );

		$entries = [];
		foreach ( $file as $line ) {
			$entry = self::parse_line( (string) $line );
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
			if ( ! empty( $actors ) && ! in_array( ltrim( $entry['actor'], '@' ), $actors, true ) ) {
				continue;
			}
			$entries[] = $entry;
		}

		unset( $file );

		return array_reverse( $entries );
	}

	/**
	 * Empties the log file.
	 *
	 * @since 1.0.0
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
	 * @since 1.0.0
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
	 * Removes entries older than the configured retention window.
	 * Called from the maintenance cron — not triggered on every write.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function trim_old_entries(): void {
		if ( ! Settings::is_logging_enabled() ) {
			return;
		}
		$days = Settings::get_log_retention_days();

		if ( ! file_exists( $this->log_file ) ) {
			return;
		}

		$cutoff = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
		$tmp    = $this->log_file . '.tmp';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$out = fopen( $tmp, 'w' );
		if ( ! $out ) {
			return;
		}

		try {
			$file = new \SplFileObject( $this->log_file, 'r' );
		} catch ( \RuntimeException $e ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $out );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			unlink( $tmp );
			return;
		}
		$file->setFlags( \SplFileObject::READ_AHEAD | \SplFileObject::SKIP_EMPTY | \SplFileObject::DROP_NEW_LINE );

		foreach ( $file as $line ) {
			$entry = self::parse_line( (string) $line );
			if ( null === $entry || substr( $entry['timestamp'], 0, 10 ) >= $cutoff ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
				fwrite( $out, $line . PHP_EOL );
			}
		}

		unset( $file );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		if ( fclose( $out ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			rename( $tmp, $this->log_file );
		} else {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			unlink( $tmp );
		}
	}

	/**
	 * Parses a single log line into a structured entry, or null if unrecognized.
	 *
	 * @since 1.0.0
	 * @param string $line Raw log line.
	 * @return array{timestamp: string, level: string, actor: string, message: string}|null
	 */
	private static function parse_line( string $line ): ?array {
		if ( ! preg_match( '/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] \[(activity|error)\](?: \[(@[^\]]+)\])? (.+)$/', $line, $m ) ) {
			return null;
		}
		return [
			'timestamp' => $m[1],
			'level'     => $m[2],
			'actor'     => '' !== $m[3] ? $m[3] : 'system',
			'message'   => $m[4],
		];
	}

	/**
	 * Called from the maintenance cron to clean up the log directory.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function purge_log_dir(): void {
		$instance = self::get_instance();
		$instance->purge_stale_log_files( dirname( $instance->log_file ) );
	}

	/**
	 * Removes anything from the log directory that should not be there.
	 *
	 * Keeps the guard files and the current log. Everything else (stale logs from a
	 * salt rotation, .tmp files from an interrupted trim) is deleted.
	 *
	 * @since 1.0.0
	 * @param string $dir Absolute path to the log directory.
	 * @return void
	 */
	private function purge_stale_log_files( string $dir ): void {
		$keep  = array_merge( Filesystem_Guard::GUARD_FILES, [ basename( $this->log_file ) ] );
		$files = glob( $dir . '/*' );
		if ( ! $files ) {
			return;
		}
		foreach ( $files as $file ) {
			if ( is_dir( $file ) ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
				@rmdir( $file );
			} elseif ( ! in_array( basename( $file ), $keep, true ) ) {
				wp_delete_file( $file );
			}
		}
	}

	/**
	 * Creates the log directory and blocks direct web access.
	 *
	 * @since 1.0.0
	 * @param string $dir Absolute path to the log directory.
	 * @return void
	 */
	private function ensure_dir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		Filesystem_Guard::protect_directory( $dir );
	}
}
