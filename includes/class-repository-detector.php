<?php
/**
 * Shared repository type detection logic.
 *
 * @package Gitwire
 * @since 1.0.0
 */

namespace Gitwire;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects whether a repository root is a WordPress plugin, theme, or unknown.
 */
class Repository_Detector {

	/**
	 * Directories that are never relevant to WP type detection.
	 *
	 * @var string[]
	 */
	private const IGNORED_DIRS = [
		'.git',
		'.github',
		'.wordpress-org',
		'node_modules',
		'vendor',
		'tests',
		'docs',
		'tools',
		'prompts',
	];

	/**
	 * File extensions that are never relevant to WP type detection.
	 *
	 * @var string[]
	 */
	private const IGNORED_EXTENSIONS = [
		// Config / manifests.
		'json',
		'lock',
		'xml',
		'yml',
		'yaml',
		'toml',
		'ini',
		// Docs / meta.
		'md',
		'txt',
		'rst',
		'dist',
		// Web assets (not WP template files).
		'js',
		'ts',
		'jsx',
		'tsx',
		'scss',
		'sass',
		'less',
		'svg',
		// Images.
		'png',
		'jpg',
		'jpeg',
		'gif',
		'ico',
		'webp',
		// Misc.
		'map',
		'log',
		'sh',
		'bash',
	];

	/**
	 * Detects repository type from root file listing callbacks.
	 *
	 * Returns an array with:
	 *   'type'       — flat value: 'plugin', 'block-theme', 'classic-theme', 'unknown'
	 *   'confidence' — 'high', 'medium', 'low', or 'none'
	 *   'name'       — extracted Theme Name or Plugin Name header value, or ''
	 *   'key_files'  — files that drove the decision; empty for low/unknown (skip shallow re-detect)
	 *
	 * @since 1.0.0
	 * @param string                    $repo_name         Repository slug used for main-file priority.
	 * @param string                    $branch            Branch ref to inspect.
	 * @param callable                  $get_root_contents Callable returning root file list.
	 * @param callable                  $get_file_content  Callable returning raw file contents.
	 * @param array<string, mixed>|null $cached_result Prior detection result for shallow re-check.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function detect(
		string $repo_name,
		string $branch,
		callable $get_root_contents,
		callable $get_file_content,
		?array $cached_result = null
	): array|\WP_Error {
		$contents = $get_root_contents( $branch );

		if ( is_wp_error( $contents ) ) {
			return $contents;
		}

		// Shallow re-check: if the cached key_files are all still present, skip file fetches.
		if (
			$cached_result &&
			! empty( $cached_result['key_files'] ) &&
			( Settings::get_public()['shallow_detection'] ?? false ) &&
			self::all_key_files_present( $cached_result['key_files'], $contents )
		) {
			return $cached_result;
		}

		$files = [];
		foreach ( $contents as $item ) {
			if ( ! isset( $item['name'] ) ) {
				continue;
			}
			$lc  = strtolower( $item['name'] );
			$ext = pathinfo( $lc, PATHINFO_EXTENSION );
			if (
				in_array( $lc, self::IGNORED_DIRS, true ) ||
				in_array( $ext, self::IGNORED_EXTENSIONS, true )
			) {
				continue;
			}
			$files[ $lc ] = $item;
		}

		// theme.json alone isn't enough — plugins ship it too; style.css + Theme Name is the real gate.
		if ( isset( $files['style.css'] ) ) {
			$css = $get_file_content( 'style.css', $branch );
			if ( ! is_wp_error( $css ) && self::has_header( $css, 'Theme Name' ) ) {
				$name = self::extract_header( $css, 'Theme Name' );

				if ( isset( $files['theme.json'] ) ) {
					return [
						'type'       => 'block-theme',
						'confidence' => 'high',
						'name'       => $name,
						'key_files'  => [ 'style.css', 'theme.json' ],
					];
				}

				if ( isset( $files['templates'] ) && ( $files['templates']['type'] ?? '' ) === 'dir' ) {
					return [
						'type'       => 'block-theme',
						'confidence' => 'high',
						'name'       => $name,
						'key_files'  => [ 'style.css', 'templates/' ],
					];
				}

				$key_files = isset( $files['functions.php'] )
					? [ 'style.css', 'functions.php' ]
					: [ 'style.css' ];

				return [
					'type'       => 'classic-theme',
					'confidence' => isset( $files['functions.php'] ) ? 'high' : 'medium',
					'name'       => $name,
					'key_files'  => $key_files,
				];
			}
		}

		$priority_names = [ strtolower( $repo_name ) . '.php', 'plugin.php', 'index.php' ];
		$php_files      = array_filter(
			array_keys( $files ),
			static fn( $n ) => str_ends_with( $n, '.php' ) && ( $files[ $n ]['type'] ?? '' ) === 'file'
		);
		usort(
			$php_files,
			static function ( $a, $b ) use ( $priority_names ) {
				$ai = array_search( $a, $priority_names, true );
				$bi = array_search( $b, $priority_names, true );
				if ( false === $ai && false === $bi ) {
					return 0;
				}
				if ( false === $ai ) {
					return 1;
				}
				if ( false === $bi ) {
					return -1;
				}
				return $ai - $bi;
			}
		);

		foreach ( array_slice( $php_files, 0, 5 ) as $lc_name ) {
			$real_name = $files[ $lc_name ]['name'];
			$content   = $get_file_content( $real_name, $branch );
			if ( ! is_wp_error( $content ) && self::has_header( $content, 'Plugin Name' ) ) {
				return [
					'type'       => 'plugin',
					'confidence' => 'high',
					'name'       => self::extract_header( $content, 'Plugin Name' ),
					'key_files'  => [ $real_name ],
				];
			}
		}

		if ( isset( $files['functions.php'] ) ) {
			return [
				'type'       => 'classic-theme',
				'confidence' => 'medium',
				'name'       => '',
				'key_files'  => [ 'functions.php' ],
			];
		}

		if ( ! empty( $php_files ) ) {
			return [
				'type'       => 'plugin',
				'confidence' => 'low',
				'name'       => '',
				'key_files'  => [],
			];
		}

		return [
			'type'       => 'unknown',
			'confidence' => 'none',
			'name'       => '',
			'key_files'  => [],
		];
	}

	/**
	 * Checks whether file content contains a WordPress-style header.
	 *
	 * @since 1.0.0
	 * @param string $content File contents.
	 * @param string $header  Header name.
	 * @return bool
	 */
	public static function has_header( string $content, string $header ): bool {
		return (bool) preg_match( '/^\s*\*?\s*' . preg_quote( $header, '/' ) . '\s*:/mi', $content );
	}

	/**
	 * Extracts a WordPress-style header value from file content.
	 *
	 * @since 1.0.0
	 * @param string $content File contents.
	 * @param string $header  Header name.
	 * @return string
	 */
	public static function extract_header( string $content, string $header ): string {
		if ( preg_match( '/^\s*\*?\s*' . preg_quote( $header, '/' ) . '\s*:\s*(.+)$/mi', $content, $m ) ) {
			return trim( $m[1] );
		}
		return '';
	}

	/**
	 * Returns true when the flat type value represents a theme.
	 *
	 * @since 2.0.0
	 * @param string $type Flat type value.
	 * @return bool
	 */
	public static function is_theme( string $type ): bool {
		return str_ends_with( $type, '-theme' );
	}

	/**
	 * Returns true when all cached key_files are still present in the root listing.
	 *
	 * @since 2.0.0
	 * @param string[]          $key_files Key files from a prior detection result.
	 * @param array<int, mixed> $contents  Root listing items from the provider.
	 * @return bool
	 */
	private static function all_key_files_present( array $key_files, array $contents ): bool {
		$names = [];
		foreach ( $contents as $item ) {
			if ( isset( $item['name'] ) ) {
				$names[ strtolower( $item['name'] ) ] = $item['type'] ?? 'file';
			}
		}

		foreach ( $key_files as $key_file ) {
			$is_dir = str_ends_with( $key_file, '/' );
			$name   = strtolower( rtrim( $key_file, '/' ) );
			$type   = $names[ $name ] ?? null;
			if ( null === $type || ( $is_dir && 'dir' !== $type ) ) {
				return false;
			}
		}
		return true;
	}
}
