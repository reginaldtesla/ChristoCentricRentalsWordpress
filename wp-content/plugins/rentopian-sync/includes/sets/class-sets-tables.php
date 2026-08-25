<?php
/**
 * Rental_Sets_Tables
 *
 * Single source of truth for the set-related table names. All other classes
 * in this module obtain their table names through this class instead of
 * reaching into `$wpdb->prefix . $rental_tables["..."]` inline. This is the
 * Single Responsibility Principle applied to table naming and the Dependency
 * Inversion Principle applied so tests can substitute names if needed.
 *
 * @package RentopianSync\Sets
 * @since   2.14.7
 *
 * PHP requirement: 7.4+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Rental_Sets_Tables', false ) ) :

/**
 * Class Rental_Sets_Tables
 *
 * Thin wrapper that resolves the four tables the sets subsystem touches:
 *   - rental_set_relations          (rental set ID -> WP post ID map per division)
 *   - rental_set_options            (set option definitions)
 *   - rental_set_option_relations   (option <-> set pivot)
 *   - rental_sets_tag_relations     (set tags pivot)
 *
 * The class is deliberately non-static so it can be injected into other
 * classes (Dependency Inversion). A static `instance()` helper is provided
 * for the common case where no injection is required.
 */
class Rental_Sets_Tables {

    /**
     * @var wpdb
     */
    protected $wpdb;

    /**
     * The rental_tables configuration array (keys -> unprefixed table names).
     *
     * @var array
     */
    protected $rental_tables;

    /**
     * Lazily cached, fully-prefixed table names.
     *
     * @var array<string,string>
     */
    protected $resolved = array();

    /**
     * Shared instance, built from globals. Code that does not need to inject
     * a custom wpdb/tables array should call `self::instance()`.
     *
     * @var self|null
     */
    protected static $shared = null;

    /**
     * Constructor.
     *
     * @param wpdb|null  $wpdb          Optional wpdb; defaults to global $wpdb.
     * @param array|null $rental_tables Optional tables config; defaults to global $rental_tables.
     */
    public function __construct( $wpdb = null, $rental_tables = null ) {
        if ( null === $wpdb ) {
            global $wpdb;
        }
        if ( null === $rental_tables ) {
            global $rental_tables;
        }

        $this->wpdb          = $wpdb;
        $this->rental_tables = is_array( $rental_tables ) ? $rental_tables : array();
    }

    /**
     * Shared instance (lazy). Uses globals.
     *
     * @return self
     */
    public static function instance() {
        if ( null === self::$shared ) {
            self::$shared = new self();
        }
        return self::$shared;
    }

    /**
     * Reset the shared instance. Primarily intended for tests.
     *
     * @return void
     */
    public static function reset_instance() {
        self::$shared = null;
    }

    /**
     * Fully-prefixed `rental_set_relations` table name.
     *
     * @return string
     */
    public function set_relations() {
        return $this->resolve( 'set_relations' );
    }

    /**
     * Fully-prefixed `rental_set_options` table name.
     *
     * @return string
     */
    public function set_options() {
        return $this->resolve( 'set_options' );
    }

    /**
     * Fully-prefixed `rental_set_option_relations` table name.
     *
     * @return string
     */
    public function set_option_relations() {
        return $this->resolve( 'set_option_relations' );
    }

    /**
     * Fully-prefixed `rental_sets_tag_relations` table name.
     *
     * @return string
     */
    public function sets_tag_relations() {
        return $this->resolve( 'sets_tag_relations' );
    }

    /**
     * Returns true if the set-option relations table actually exists in the
     * database. Matches the guard used in `rental_empty_set_options` /
     * `rental_add_set_options` exactly.
     *
     * @return bool
     */
    public function set_option_relations_table_exists() {
        $table = $this->set_option_relations();
        return (string) $this->wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table;
    }

    /**
     * Access the wpdb used by this instance. Provided for classes that
     * receive a Tables instance and want to stay out of the global scope.
     *
     * @return wpdb
     */
    public function db() {
        return $this->wpdb;
    }

    /**
     * Resolve a rental_tables key into its prefixed table name, with caching.
     *
     * @param string $key One of: set_relations|set_options|set_option_relations|sets_tag_relations.
     * @return string
     */
    protected function resolve( $key ) {
        if ( isset( $this->resolved[ $key ] ) ) {
            return $this->resolved[ $key ];
        }

        $unprefixed = isset( $this->rental_tables[ $key ] ) ? (string) $this->rental_tables[ $key ] : '';
        $this->resolved[ $key ] = $this->wpdb->prefix . $unprefixed;

        return $this->resolved[ $key ];
    }
}

endif;
