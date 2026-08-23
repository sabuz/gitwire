<?php
/**
 * Plugin constants holder.
 *
 * @package Gitwire
 * @since 1.0.0
 */

namespace Gitwire;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Centralises plugin path and version constants.
 */
final class Constants {

	/**
	 * Option caching the parsed plugin version against the file's mtime.
	 *
	 * @var string
	 */
	const VERSION_CACHE = 'gitwire_version_cache';

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Plugin version string.
	 *
	 * @var string
	 */
	public string $version;

	/**
	 * Main plugin file path.
	 *
	 * @var string
	 */
	public string $file;

	/**
	 * Plugin directory path.
	 *
	 * @var string
	 */
	public string $dir;

	/**
	 * Plugin URL.
	 *
	 * @var string
	 */
	public string $url;

	/**
	 * Plugin basename.
	 *
	 * @var string
	 */
	public string $basename;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param string $file Main plugin file path.
	 */
	private function __construct( string $file ) {
		$this->file     = $file;
		$this->dir      = plugin_dir_path( $file );
		$this->url      = plugin_dir_url( $file );
		$this->basename = plugin_basename( $file );

		/*
		 * get_file_data() reads and regex-scans the plugin file, and this runs on every
		 * request. One autoloaded option keyed on mtime, replaced rather than added to,
		 * so a new build still picks the version up.
		 */
		$mtime  = (int) filemtime( $file );
		$cached = get_option( self::VERSION_CACHE );

		if ( is_array( $cached ) && (int) ( $cached['mtime'] ?? 0 ) === $mtime && ! empty( $cached['version'] ) ) {
			$this->version = (string) $cached['version'];
			return;
		}

		$header        = get_file_data( $file, [ 'version' => 'Version' ] );
		$this->version = '' !== $header['version'] ? $header['version'] : '0.0.0';

		update_option(
			self::VERSION_CACHE,
			[
				'mtime'   => $mtime,
				'version' => $this->version,
			],
			true
		);
	}

	/**
	 * Returns the singleton instance.
	 *
	 * @since 1.0.0
	 * @param string $file Main plugin file path.
	 * @return self
	 */
	public static function instance( string $file ): self {
		if ( null === self::$instance ) {
			self::$instance = new self( $file );
		}
		return self::$instance;
	}
}
