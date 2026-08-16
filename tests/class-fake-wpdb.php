<?php
/**
 * Recording wpdb double for the unit suite.
 *
 * Enough of the surface to assert what SQL a model emits and how many
 * statements it takes, without needing a database.
 *
 * @package Gitwire
 */

/**
 * Captures every prepared statement instead of executing it.
 */
class Fake_WPDB {

	/**
	 * Table prefix.
	 *
	 * @var string
	 */
	public $base_prefix = 'wp_';

	/**
	 * Database name.
	 *
	 * @var string
	 */
	public $dbname = 'gitwire_test';

	/**
	 * Options table name.
	 *
	 * @var string
	 */
	public $options = 'wp_options';

	/**
	 * Every SQL string passed to query().
	 *
	 * @var string[]
	 */
	public $queries = [];

	/**
	 * Value returned by get_var().
	 *
	 * @var mixed
	 */
	public $var_result = null;

	/**
	 * Value returned by get_row().
	 *
	 * @var mixed
	 */
	public $row_result = null;

	/**
	 * Value returned by get_results()/get_col().
	 *
	 * @var array<int, mixed>
	 */
	public $results = [];

	/**
	 * Substitutes placeholders well enough to inspect the result.
	 *
	 * @param string $query SQL with %s/%d placeholders.
	 * @param mixed  ...$args Values, or a single array of them.
	 * @return string
	 */
	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}

		$i = 0;
		return (string) preg_replace_callback(
			'/%[sdf]/',
			static function ( $m ) use ( &$i, $args ) {
				$value = $args[ $i ] ?? null;
				++$i;
				if ( '%d' === $m[0] ) {
					return (string) (int) $value;
				}
				return "'" . str_replace( "'", "\\'", (string) $value ) . "'";
			},
			$query
		);
	}

	/**
	 * Records a statement.
	 *
	 * @param string $query SQL.
	 * @return int
	 */
	public function query( $query ) {
		$this->queries[] = $query;
		return 1;
	}

	/**
	 * @param string $query SQL.
	 * @param mixed  $output Ignored.
	 * @return mixed
	 */
	public function get_var( $query = null, $output = 0 ) {
		$this->queries[] = (string) $query;
		return $this->var_result;
	}

	/**
	 * @param string $query  SQL.
	 * @param string $output Ignored.
	 * @return mixed
	 */
	public function get_row( $query = null, $output = 'OBJECT' ) {
		$this->queries[] = (string) $query;
		return $this->row_result;
	}

	/**
	 * @param string $query  SQL.
	 * @param string $output Ignored.
	 * @return array<int, mixed>
	 */
	public function get_results( $query = null, $output = 'OBJECT' ) {
		$this->queries[] = (string) $query;
		return $this->results;
	}

	/**
	 * @param string $query SQL.
	 * @param int    $column Ignored.
	 * @return array<int, mixed>
	 */
	public function get_col( $query = null, $column = 0 ) {
		$this->queries[] = (string) $query;
		return $this->results;
	}

	/**
	 * @param string               $table Table name.
	 * @param array<string, mixed> $data  Row data.
	 * @param array<string, mixed> $where Conditions.
	 * @return int
	 */
	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		$this->queries[] = 'UPDATE ' . $table . ' SET ' . implode( ',', array_keys( $data ) )
			. ' WHERE ' . implode( ',', array_keys( $where ) );
		return 1;
	}

	/**
	 * @param string               $table Table name.
	 * @param array<string, mixed> $where Conditions.
	 * @return int
	 */
	public function delete( $table, $where, $where_format = null ) {
		$this->queries[] = 'DELETE FROM ' . $table . ' WHERE ' . implode( ',', array_keys( $where ) );
		return 1;
	}

	/**
	 * @param string               $table Table name.
	 * @param array<string, mixed> $data  Row data.
	 * @return int
	 */
	public function insert( $table, $data, $format = null ) {
		$this->queries[] = 'INSERT INTO ' . $table . ' (' . implode( ',', array_keys( $data ) ) . ')';
		return 1;
	}

	/**
	 * @param string $text Value to escape.
	 * @return string
	 */
	public function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}

	/**
	 * @return string
	 */
	public function get_charset_collate() {
		return 'DEFAULT CHARACTER SET utf8mb4';
	}

	/**
	 * Drops recorded statements.
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->queries = [];
	}

	/**
	 * Returns recorded statements containing a fragment.
	 *
	 * @param string $needle Fragment to match.
	 * @return string[]
	 */
	public function queries_matching( string $needle ): array {
		return array_values(
			array_filter( $this->queries, static fn( $q ) => str_contains( $q, $needle ) )
		);
	}
}
