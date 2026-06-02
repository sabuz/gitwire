<?php
/**
 * Shared repository type detection logic.
 *
 * @package Gitwire
 * @since 1.2.0
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
	 * Detects repository type from root file listing callbacks.
	 *
	 * @since 1.2.0
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
			if ( isset( $item['name'] ) ) {
				$files[ strtolower( $item['name'] ) ] = $item;
			}
		}

		// style.css with Theme Name is the only canonical WordPress indicator that a repo is a theme.
		// theme.json alone is not sufficient — plugins routinely ship one for block styling.
		if ( isset( $files['style.css'] ) ) {
			$css = $get_file_content( 'style.css', $branch );
			if ( ! is_wp_error( $css ) && self::has_header( $css, 'Theme Name' ) ) {
				$name = self::extract_header( $css, 'Theme Name' );

				// Block theme: theme.json is the strongest signal (required for FSE).
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
	 * @since 1.2.0
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
	 * @since 1.2.0
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
