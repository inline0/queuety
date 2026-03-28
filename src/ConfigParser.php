<?php
/**
 * WordPress config file parser.
 *
 * @package Queuety
 */

namespace Queuety;

/**
 * Parses wp-config.php via regex to extract database credentials
 * without requiring or executing the file.
 */
class ConfigParser {

	/**
	 * Extract database credentials from a wp-config.php file.
	 *
	 * @param string $path Absolute path to wp-config.php.
	 * @return array{host: string, name: string, user: string, password: string, prefix: string}
	 * @throws \RuntimeException If the file cannot be read or required values are missing.
	 */
	public static function from_wp_config( string $path ): array {
		if ( ! is_readable( $path ) ) {
			throw new \RuntimeException( "Cannot read wp-config.php at: {$path}" );
		}

		$contents = file_get_contents( $path );
		if ( false === $contents ) {
			throw new \RuntimeException( "Failed to read wp-config.php at: {$path}" );
		}

		$constants = array(
			'DB_HOST'     => 'host',
			'DB_NAME'     => 'name',
			'DB_USER'     => 'user',
			'DB_PASSWORD' => 'password',
		);

		$result = array();
		foreach ( $constants as $constant => $key ) {
			// Match: define( 'CONSTANT', 'value' ) with single or double quotes.
			$pattern = '/define\s*\(\s*[\'"]' . preg_quote( $constant, '/' ) . '[\'"]\s*,\s*[\'"]([^\'"]*)[\'"]\s*\)/';
			if ( preg_match( $pattern, $contents, $matches ) ) {
				$result[ $key ] = $matches[1];
			} else {
				throw new \RuntimeException( "Could not find {$constant} in wp-config.php" );
			}
		}

		// Match the table prefix variable.
		$result['prefix'] = 'wp_';
		if ( preg_match( '/\$table_prefix\s*=\s*[\'"]([^\'"]*)[\'"]\s*;/', $contents, $matches ) ) {
			$result['prefix'] = $matches[1];
		}

		return $result;
	}
}
