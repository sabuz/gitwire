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
class Repo_Detector {

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
	 * @since 1.0.0
	 * @param string   $repo_name         Repository slug used for main-file priority.
	 * @param string   $branch            Branch ref to inspect.
	 * @param callable $get_root_contents Callable returning root file list.
	 * @param callable $get_file_content  Callable returning raw file contents.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function detect(
		string $repo_name,
		string $branch,
		callable $get_root_contents,
		callable $get_file_content
	): array|\WP_Error {
		$contents = $get_root_contents( $branch );

		if ( is_wp_error( $contents ) ) {
			return $contents;
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
						'type'       => 'theme',
						'subtype'    => 'block',
						'confidence' => 'high',
						'name'       => $name,
					];
				}

				if ( isset( $files['templates'] ) && ( $files['templates']['type'] ?? '' ) === 'dir' ) {
					return [
						'type'       => 'theme',
						'subtype'    => 'block',
						'confidence' => 'high',
						'name'       => $name,
					];
				}

				return [
					'type'       => 'theme',
					'subtype'    => 'classic',
					'confidence' => isset( $files['functions.php'] ) ? 'high' : 'medium',
					'name'       => $name,
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
					'subtype'    => null,
					'confidence' => 'high',
					'name'       => self::extract_header( $content, 'Plugin Name' ),
				];
			}
		}

		if ( isset( $files['functions.php'] ) ) {
			return [
				'type'       => 'theme',
				'subtype'    => 'classic',
				'confidence' => 'medium',
				'name'       => '',
			];
		}

		if ( ! empty( $php_files ) ) {
			return [
				'type'       => 'plugin',
				'subtype'    => null,
				'confidence' => 'low',
				'name'       => '',
			];
		}

		return [
			'type'       => 'unknown',
			'subtype'    => null,
			'confidence' => 'none',
			'name'       => '',
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
}
