<?php
/**
 * Plugin constants holder.
 *
 * @package Gitwire
 * @since 1.2.0
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
	 * @param string $file Main plugin file path.
	 */
	private function __construct( string $file ) {
		$this->file     = $file;
		$this->dir      = plugin_dir_path( $file );
		$this->url      = plugin_dir_url( $file );
		$this->basename = plugin_basename( $file );

		$header        = get_file_data( $file, [ 'version' => 'Version' ] );
		$this->version = '' !== $header['version'] ? $header['version'] : '0.0.0';
	}

	/**
	 * Returns the singleton instance.
	 *
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
