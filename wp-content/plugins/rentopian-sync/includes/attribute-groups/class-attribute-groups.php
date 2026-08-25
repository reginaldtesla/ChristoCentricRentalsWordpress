<?php
/**
 * Attribute value groups — storage and read API.
 *
 * A group is an attribute value carrying `is_group = 1`. It is an ordinary
 * WooCommerce term in the attribute taxonomy, with its own colour / image, and
 * it owns a set of member values. Groups are never linked to a variant, so a
 * `hide_empty` term query — which is what the product page, the product loop
 * and the filter facet all use — never returns one. That is what keeps the
 * storefront byte-identical until the facet is explicitly told to use groups.
 *
 * Membership is stored by Rentopian id, never by term id: a membership sync may
 * arrive before the member value's own `create` webhook, and an id that cannot
 * be resolved yet simply contributes nothing until the value shows up.
 *
 * @package RentopianSync\AttributeGroups
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Rental_Attribute_Groups {

    /** Facet grouping toggle. Off means the storefront behaves exactly as before. */
    const SETTING = 'rental_group_attribute_values_in_filters';

    const SCHEMA_OPTION = 'rental_attribute_groups_schema_version';

    const SCHEMA_VERSION = '1';

    /** Bumped on every write, so cached facet reads fall out of use at once. */
    const CACHE_VERSION_OPTION = 'rental_attribute_groups_cache_version';

    const CACHE_TTL = 12 * HOUR_IN_SECONDS;

    /** Resolved groups per taxonomy for this request. */
    private static $group_cache = [];

    /** Whether both tables are present. Null until checked. */
    private static $tables_ready = null;

    /** A write happened; the shared cache version is bumped once, at shutdown. */
    private static $cache_flush_pending = false;

    /* ──────────────────────────────────────────────────────────
     * Schema
     * ────────────────────────────────────────────────────────── */

    /**
     * Value id ↔ term id map, with the group flag.
     *
     * @return string
     */
    public static function values_table() {
        global $wpdb, $rental_tables;

        return $wpdb->prefix . $rental_tables['attribute_value_relations'];
    }

    /**
     * Group → member map, both sides Rentopian ids.
     *
     * @return string
     */
    public static function groups_table() {
        global $wpdb, $rental_tables;

        return $wpdb->prefix . $rental_tables['attribute_value_groups'];
    }

    /**
     * Create the tables when missing.
     *
     * `rental_create_tables()` only runs on activation, so a site that receives
     * this as a plugin update would otherwise never get them and the whole
     * feature would silently do nothing. Version-guarded, so the steady-state
     * cost is one option read.
     *
     * @param bool $force Ignore the version guard.
     * @return void
     */
    public static function ensure_tables( $force = false ) {
        global $wpdb;

        if ( ! $force && get_option( self::SCHEMA_OPTION ) === self::SCHEMA_VERSION ) {
            return;
        }

        $values = self::values_table();
        $groups = self::groups_table();
        $sql    = '';

        if ( $wpdb->get_var( "show tables like '$values'" ) != $values ) {
            $sql .= "CREATE TABLE $values (
                id BIGINT(11) NOT NULL,
                rental_id BIGINT(11) NOT NULL,
                rental_attribute_id BIGINT(11) NOT NULL,
                is_group TINYINT(1) NOT NULL DEFAULT 0,
                UNIQUE KEY id (id),
                UNIQUE KEY rental_id (rental_id),
                KEY attribute_lookup (rental_attribute_id, is_group)
            );";
        }

        if ( $wpdb->get_var( "show tables like '$groups'" ) != $groups ) {
            $sql .= "CREATE TABLE $groups (
                group_rental_id BIGINT(11) NOT NULL,
                member_rental_id BIGINT(11) NOT NULL,
                rental_attribute_id BIGINT(11) NOT NULL,
                UNIQUE KEY membership (group_rental_id, member_rental_id),
                KEY member_lookup (member_rental_id),
                KEY attribute_lookup (rental_attribute_id)
            );";
        }

        if ( $sql ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta( $sql );
        }

        self::$tables_ready = null;

        update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, true );
    }

    /**
     * Whether both tables exist. Everything else no-ops when they do not, so a
     * half-migrated site degrades to the previous behaviour instead of erroring.
     *
     * @return bool
     */
    public static function tables_ready() {
        global $wpdb, $rental_tables;

        if ( null !== self::$tables_ready ) {
            return self::$tables_ready;
        }

        if ( empty( $rental_tables['attribute_value_relations'] ) || empty( $rental_tables['attribute_value_groups'] ) ) {
            self::$tables_ready = false;

            return false;
        }

        $values = self::values_table();
        $groups = self::groups_table();

        self::$tables_ready = ( $wpdb->get_var( "show tables like '$values'" ) == $values )
            && ( $wpdb->get_var( "show tables like '$groups'" ) == $groups );

        return self::$tables_ready;
    }

    /* ──────────────────────────────────────────────────────────
     * Writes
     * ────────────────────────────────────────────────────────── */

    /**
     * Record which term a Rentopian value lives in, and whether it is a group.
     *
     * @param int      $term_id
     * @param int      $rental_id           Rentopian value id.
     * @param int      $rental_attribute_id
     * @param int|null $is_group            NULL when the payload said nothing
     *                                      about grouping, which leaves the
     *                                      flag and the members as they are.
     * @return bool
     */
    public static function save_value( $term_id, $rental_id, $rental_attribute_id, $is_group ) {
        global $wpdb;

        $term_id   = (int) $term_id;
        $rental_id = (int) $rental_id;

        if ( ! $term_id || ! $rental_id || ! self::tables_ready() ) {
            return false;
        }

        $table               = self::values_table();
        $rental_attribute_id = (int) $rental_attribute_id;

        // Only a payload that says so demotes a group. An ordinary value
        // update that carries no flag — an older core, a partial payload —
        // must not silently strip a group of its members.
        if ( null === $is_group ) {
            $is_group = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT `is_group` FROM `$table` WHERE `rental_id` = %d",
                $rental_id
            ) );
            $demoted  = false;
        } else {
            $is_group = $is_group ? 1 : 0;
            $demoted  = ! $is_group;
        }

        // A value keeps one term and one id, but either side can move: a term
        // rebuilt by a resync takes a new id, and an id can be re-pointed at a
        // different term. Both stale rows go before the current pair lands.
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM `$table` WHERE `id` = %d OR `rental_id` = %d",
            $term_id,
            $rental_id
        ) );

        $saved = $wpdb->insert( $table, [
            'id'                  => $term_id,
            'rental_id'           => $rental_id,
            'rental_attribute_id' => $rental_attribute_id,
            'is_group'            => $is_group,
        ] );

        if ( $demoted ) {
            self::clear_group( $rental_id );
        }

        self::flush_cache();

        return (bool) $saved;
    }

    /**
     * Drop a value and every membership row that references it, on either side.
     *
     * Mirrors the delete Rentopian has already performed.
     *
     * @param int $rental_id Rentopian value id.
     * @return void
     */
    public static function delete_value( $rental_id ) {
        global $wpdb;

        $rental_id = (int) $rental_id;

        if ( ! $rental_id || ! self::tables_ready() ) {
            return;
        }

        $values = self::values_table();
        $groups = self::groups_table();

        $wpdb->query( $wpdb->prepare( "DELETE FROM `$values` WHERE `rental_id` = %d", $rental_id ) );
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM `$groups` WHERE `group_rental_id` = %d OR `member_rental_id` = %d",
            $rental_id,
            $rental_id
        ) );

        self::flush_cache();
    }

    /**
     * Replace a group's entire member set.
     *
     * Idempotent: the same payload delivered twice leaves the same rows. An
     * empty member list is a valid state — the group simply has no members.
     * Member ids are stored whether or not the value exists here yet.
     *
     * @param int   $group_rental_id
     * @param int   $rental_attribute_id
     * @param array $member_rental_ids
     * @return int Rows stored.
     */
    public static function replace_members( $group_rental_id, $rental_attribute_id, array $member_rental_ids ) {
        global $wpdb;

        $group_rental_id = (int) $group_rental_id;

        if ( ! $group_rental_id || ! self::tables_ready() ) {
            return 0;
        }

        self::clear_group( $group_rental_id );

        $rental_attribute_id = (int) $rental_attribute_id;
        $members             = [];

        foreach ( $member_rental_ids as $member_id ) {
            $member_id = (int) $member_id;

            // A group holding itself would filter to itself, which matches no
            // product, so it is dropped rather than stored.
            if ( $member_id > 0 && $member_id !== $group_rental_id ) {
                $members[ $member_id ] = $member_id;
            }
        }

        $stored = 0;
        foreach ( $members as $member_id ) {
            $stored += (int) $wpdb->insert( self::groups_table(), [
                'group_rental_id'     => $group_rental_id,
                'member_rental_id'    => $member_id,
                'rental_attribute_id' => $rental_attribute_id,
            ] );
        }

        self::flush_cache();

        return $stored;
    }

    /**
     * Remove every member of one group, leaving the group value itself.
     *
     * @param int $group_rental_id
     * @return void
     */
    public static function clear_group( $group_rental_id ) {
        global $wpdb;

        $group_rental_id = (int) $group_rental_id;

        if ( ! $group_rental_id || ! self::tables_ready() ) {
            return;
        }

        $wpdb->query( $wpdb->prepare(
            "DELETE FROM `" . self::groups_table() . "` WHERE `group_rental_id` = %d",
            $group_rental_id
        ) );

        self::flush_cache();
    }

    /**
     * Rebuild the whole membership map from a full pull.
     *
     * The caller only reaches this with an answered request: membership is
     * replaced wholesale, so calling it with the empty result of a failed fetch
     * would erase every group on the site.
     *
     * @param array $pairs Rows of `group_value_id`, `value_id`, `attribute_id`.
     * @return int Rows stored.
     */
    public static function replace_all_membership( $pairs ) {
        global $wpdb;

        if ( ! self::tables_ready() ) {
            return 0;
        }

        $rows = [];
        foreach ( (array) $pairs as $pair ) {
            $pair      = (object) $pair;
            $group_id  = isset( $pair->group_value_id ) ? (int) $pair->group_value_id : 0;
            $member_id = isset( $pair->value_id ) ? (int) $pair->value_id : 0;

            if ( ! $group_id || ! $member_id || $group_id === $member_id ) {
                continue;
            }

            $attribute_id = isset( $pair->attribute_id ) ? (int) $pair->attribute_id : 0;

            $rows[ $group_id . ':' . $member_id ] = "($group_id, $member_id, $attribute_id)";
        }

        $table = self::groups_table();
        $wpdb->query( "TRUNCATE TABLE `$table`" );

        if ( $rows ) {
            foreach ( array_chunk( $rows, 500 ) as $chunk ) {
                $wpdb->query(
                    "INSERT INTO `$table` (`group_rental_id`, `member_rental_id`, `rental_attribute_id`) VALUES "
                    . implode( ', ', $chunk )
                );
            }
        }

        self::flush_cache();

        return count( $rows );
    }

    /**
     * Forget the value ↔ term map, for a rebuild that drops every term.
     *
     * Membership survives: it is keyed by Rentopian id, and the values are
     * about to be recreated.
     *
     * @return void
     */
    public static function empty_value_relations() {
        global $wpdb;

        if ( ! self::tables_ready() ) {
            return;
        }

        $wpdb->query( 'TRUNCATE TABLE `' . self::values_table() . '`' );

        self::flush_cache();
    }

    /* ──────────────────────────────────────────────────────────
     * Reads
     * ────────────────────────────────────────────────────────── */

    /**
     * Whether the facet should render groups.
     *
     * @return bool
     */
    public static function enabled() {
        return (bool) get_option( self::SETTING ) && self::tables_ready();
    }

    /**
     * Groups of one attribute taxonomy, resolved to term ids.
     *
     * Groups with no resolvable member are left out: they can never match a
     * product, so they would only ever render as an empty facet entry.
     *
     * @param string $taxonomy Attribute taxonomy, e.g. `pa_color`.
     * @return array group term id => member term ids.
     */
    public static function groups_for_taxonomy( $taxonomy ) {
        global $wpdb;

        $taxonomy = (string) $taxonomy;

        if ( isset( self::$group_cache[ $taxonomy ] ) ) {
            return self::$group_cache[ $taxonomy ];
        }

        if ( ! self::tables_ready() ) {
            self::$group_cache[ $taxonomy ] = [];

            return [];
        }

        // While a flush is pending the version has not moved yet, so the stored
        // entry is the one this request just invalidated.
        $cache_key = self::cache_key( $taxonomy );
        $cached    = self::$cache_flush_pending ? false : get_transient( $cache_key );

        if ( is_array( $cached ) ) {
            self::$group_cache[ $taxonomy ] = $cached;

            return $cached;
        }

        $groups        = [];
        $attribute_id  = self::attribute_rental_id( $taxonomy );

        if ( $attribute_id ) {
            $values_table = self::values_table();
            $groups_table = self::groups_table();

            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT `groups`.`group_rental_id`, `group_value`.`id` AS `group_term_id`, `member_value`.`id` AS `member_term_id`
                 FROM `$groups_table` AS `groups`
                 INNER JOIN `$values_table` AS `group_value`
                    ON `group_value`.`rental_id` = `groups`.`group_rental_id` AND `group_value`.`is_group` = 1
                 INNER JOIN `$values_table` AS `member_value`
                    ON `member_value`.`rental_id` = `groups`.`member_rental_id`
                 WHERE `group_value`.`rental_attribute_id` = %d",
                $attribute_id
            ) );

            foreach ( (array) $rows as $row ) {
                $group_term_id  = (int) $row->group_term_id;
                $member_term_id = (int) $row->member_term_id;

                if ( ! $group_term_id || ! $member_term_id ) {
                    continue;
                }

                $groups[ $group_term_id ][ $member_term_id ] = $member_term_id;
            }

            foreach ( $groups as $group_term_id => $members ) {
                $groups[ $group_term_id ] = array_values( $members );
            }
        }

        if ( ! self::$cache_flush_pending ) {
            set_transient( $cache_key, $groups, self::CACHE_TTL );
        }

        self::$group_cache[ $taxonomy ] = $groups;

        return $groups;
    }

    /**
     * Rentopian attribute id behind an attribute taxonomy.
     *
     * @param string $taxonomy
     * @return int 0 when the taxonomy is not a synced attribute.
     */
    private static function attribute_rental_id( $taxonomy ) {
        global $wpdb, $rental_tables;

        $attribute_name = substr( (string) $taxonomy, 0, 3 ) === 'pa_'
            ? substr( (string) $taxonomy, 3 )
            : (string) $taxonomy;

        if ( '' === $attribute_name || empty( $rental_tables['attribute_relations'] ) ) {
            return 0;
        }

        $relations = $wpdb->prefix . $rental_tables['attribute_relations'];

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT `relations`.`rental_id` FROM `$relations` AS `relations`
             INNER JOIN `" . $wpdb->prefix . "woocommerce_attribute_taxonomies` AS `attributes`
                ON `attributes`.`attribute_id` = `relations`.`id`
             WHERE `attributes`.`attribute_name` = %s",
            $attribute_name
        ) );
    }

    /* ──────────────────────────────────────────────────────────
     * Cache
     * ────────────────────────────────────────────────────────── */

    private static function cache_key( $taxonomy ) {
        return 'rental_attr_groups_' . md5( $taxonomy . '_' . (int) get_option( self::CACHE_VERSION_OPTION, 0 ) );
    }

    /**
     * Retire every cached facet read.
     *
     * The bump itself is deferred to shutdown so a sync writing thousands of
     * values costs one option write rather than thousands; until it lands, the
     * transient is bypassed, so a request that writes and then reads still sees
     * its own change.
     *
     * @return void
     */
    private static function flush_cache() {
        self::$group_cache = [];

        if ( self::$cache_flush_pending ) {
            return;
        }

        self::$cache_flush_pending = true;
        add_action( 'shutdown', [ __CLASS__, 'commit_cache_flush' ], 1 );
    }

    /**
     * Apply the deferred cache-version bump. Public only because it is a hook
     * callback.
     *
     * @return void
     */
    public static function commit_cache_flush() {
        if ( ! self::$cache_flush_pending ) {
            return;
        }

        self::$cache_flush_pending = false;

        update_option( self::CACHE_VERSION_OPTION, (int) get_option( self::CACHE_VERSION_OPTION, 0 ) + 1, true );
    }
}
