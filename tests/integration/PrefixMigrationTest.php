<?php
/**
 * Integration tests for the sd_ → skdon_ storage move (#30).
 *
 * Seeds a 2.x install's data under the old names the way 2.x stored it —
 * directly, since the old post types and taxonomies are not registered any
 * more — then runs the migration and checks every place a name lives.
 *
 * The activity-log tables are TEMPORARY (the suite rewrites CREATE/DROP
 * TABLE), so the test shadows the real table with a temporary one of the
 * same name and nothing leaves the per-test transaction.
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Tests\Integration;

use Starter_Shelter\Core\Prefix_Migration;
use WP_UnitTestCase;

final class PrefixMigrationTest extends WP_UnitTestCase {

	/** @var array<string, int> */
	private array $ids = [];

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		foreach ( [ 'sd_activity_log', 'skdon_activity_log' ] as $table ) {
			// Becomes DROP TEMPORARY TABLE: it only ever removes an earlier
			// test's shadow, never the real table.
			$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}{$table}`" );
			$wpdb->query( $this->log_table_sql( $wpdb->prefix . $table ) );
		}
		$this->seed_old_install();
		$this->seed_neighbours();
	}

	public function tear_down(): void {
		wp_clear_scheduled_hook( 'sd_cleanup_activity_log' );
		wp_clear_scheduled_hook( 'skdon_cleanup_activity_log' );
		parent::tear_down();
	}

	public function test_moves_every_stored_name(): void {
		global $wpdb;
		$counts = Prefix_Migration::run();
		wp_cache_flush();
		$ids = $this->ids;

		// Post types, and menu items pointing at one.
		$this->assertSame( 'skdon_donation', get_post_type( $ids['donation'] ) );
		$this->assertSame( 'skdon_memorial', get_post_type( $ids['memorial'] ) );
		$this->assertSame( 'skdon_memorial', get_post_meta( $ids['menu_item'], '_menu_item_object', true ) );

		// Meta, with values intact.
		$this->assertSame( '50', get_post_meta( $ids['donation'], '_skdon_amount', true ) );
		$this->assertSame( 'medical-care', get_post_meta( $ids['donation'], '_skdon_allocation', true ) );
		$this->assertSame( '', get_post_meta( $ids['donation'], '_sd_amount', true ) );
		$this->assertSame( '500', get_term_meta( $ids['term'], '_skdon_goal', true ) );
		$this->assertSame( [ $ids['memorial'] ], get_user_meta( $ids['user'], '_skdon_candles', true ) );
		$this->assertSame( 'approved', get_post_meta( $ids['logo'], '_skdon_logo_status', true ) );
		$this->assertSame( 'facility', get_post_meta( $ids['variation'], '_skdon_allocation', true ) );

		// Taxonomy: the term and its relationship move together.
		$this->assertSame( 'skdon_campaign', $wpdb->get_var( $wpdb->prepare( "SELECT taxonomy FROM {$wpdb->term_taxonomy} WHERE term_id = %d", $ids['term'] ) ) );
		$this->assertSame( [ $ids['term'] ], wp_get_object_terms( $ids['donation'], 'skdon_campaign', [ 'fields' => 'ids' ] ) );
		$this->assertSame( [ 7 ], get_option( 'skdon_campaign_children' ) );

		// Options, a Config override, and WooCommerce's saved email settings.
		$this->assertSame( '123', (string) get_option( 'skdon_donation_product_id' ) );
		$this->assertFalse( get_option( 'sd_donation_product_id' ) );
		$this->assertSame( [ 'bedding' => 'Bedding' ], get_option( 'skdon_config_settings_allocations' ) );
		$this->assertSame( [ 'enabled' => 'no' ], get_option( 'woocommerce_skdon_donation_receipt_settings' ) );

		// Per-user option (behind the blog prefix) and a screen preference.
		$this->assertSame( 'year', get_user_option( 'skdon_dashboard_period', $ids['user'] ) );
		$this->assertSame( [ 'sd_admin_notes' ], get_user_meta( $ids['user'], 'metaboxhidden_skdon_membership', true ), 'Meta box ids are markup and keep their names.' );

		// Activity log: the table and the post type names it stores.
		$log = $wpdb->prefix . 'skdon_activity_log';
		$this->assertSame( 'skdon_donation', $wpdb->get_var( "SELECT object_type FROM `{$log}` WHERE event_type = 'donation_created'" ) );
		$this->assertFalse( $this->table_exists( $wpdb->prefix . 'sd_activity_log' ) );

		// Saved template, block attributes (and not prose), cron, transients.
		$this->assertSame( 'single-skdon_memorial', get_post_field( 'post_name', $ids['template'] ) );
		$content = get_post_field( 'post_content', $ids['page'] );
		$this->assertStringContainsString( '"postType":"skdon_memorial"', $content );
		$this->assertStringContainsString( '"skdon_memorial_year":[3]', $content );
		$this->assertStringContainsString( 'the sd_memorial post type', $content, 'Prose is never rewritten.' );
		$this->assertFalse( wp_next_scheduled( 'sd_cleanup_activity_log' ) );
		$this->assertFalse( get_transient( 'sd_menu_pending_count' ) );
		$this->assertFalse( get_transient( 'sd_donor_id_5' ) );

		$this->assertSame( [], Prefix_Migration::leftovers() );
		$this->assertGreaterThan( 0, $counts['meta keys'] );
	}

	public function test_merges_the_duplicate_keys(): void {
		Prefix_Migration::run();
		wp_cache_flush();
		$ids = $this->ids;

		// _sd_date goes; a record without the real key keeps its date.
		$this->assertSame( '2025-03-01 10:00:00', get_post_meta( $ids['donation'], '_skdon_donation_date', true ), 'The real key wins over a stale copy.' );
		$this->assertSame( '2024-12-24 09:00:00', get_post_meta( $ids['old_donation'], '_skdon_donation_date', true ), 'A record with only the copy keeps its date.' );
		foreach ( [ $ids['donation'], $ids['old_donation'] ] as $id ) {
			$this->assertSame( [], get_post_meta( $id, '_sd_date' ) );
		}
		$this->assertSame( '2001-01-01', get_post_meta( $ids['neighbour_post'], '_sd_date', true ), 'Only donations and memorials lose _sd_date.' );

		// The nested family-notification array becomes the flat keys...
		$this->assertSame( '1', get_post_meta( $ids['memorial'], '_skdon_notify_family_enabled', true ) );
		$this->assertSame( 'family@example.test', get_post_meta( $ids['memorial'], '_skdon_notify_family_email', true ) );
		$this->assertSame( '', get_post_meta( $ids['memorial'], '_skdon_notify_family', true ) );
		$this->assertSame(
			[ 'enabled' => true, 'name' => 'The Smiths', 'email' => 'family@example.test', 'address' => '', 'send_card' => false ],
			\Starter_Shelter\Helpers\get_memorial_notify_family( $ids['memorial'] )
		);
		// ...unless the memorial already has flat keys, which were written later.
		$this->assertSame( 'later@example.test', get_post_meta( $ids['memorial_flat'], '_skdon_notify_family_email', true ) );
		$this->assertSame( '', get_post_meta( $ids['memorial_flat'], '_skdon_notify_family_name', true ) );
		$this->assertSame( '', get_post_meta( $ids['memorial_flat'], '_skdon_notify_family', true ) );
	}

	public function test_second_run_changes_nothing(): void {
		Prefix_Migration::run();
		$this->assertSame( 0, array_sum( Prefix_Migration::run() ) );
	}

	public function test_leaves_other_plugins_sd_data_alone(): void {
		global $wpdb;
		Prefix_Migration::run();
		wp_cache_flush();
		$ids = $this->ids;

		$this->assertSame( 'keep', get_option( 'sd_neighbour_setting' ) );
		$this->assertSame( 'keep', get_post_meta( $ids['neighbour_post'], '_sd_neighbour', true ) );
		$this->assertSame( 'keep', get_post_meta( $ids['neighbour_post'], '_sd_email', true ), 'A key on our list, on someone else\'s post.' );
		$this->assertSame( 'keep', get_post_meta( $ids['neighbour_type'], '_sd_amount', true ) );
		$this->assertSame( 'keep', get_post_meta( $ids['neighbour_attachment'], '_sd_amount', true ) );
		$this->assertSame( 'keep', get_post_meta( $ids['neighbour_product'], '_sd_amount', true ) );
		$this->assertSame( 'keep', get_term_meta( $ids['neighbour_term'], '_sd_goal', true ) );
		$this->assertSame( 'keep', get_user_meta( $ids['user'], '_sd_neighbour', true ) );
		$this->assertSame( 'keep', get_transient( 'sd_neighbour_cache' ) );
		$this->assertSame( 'sd_neighbour', get_post_type( $ids['neighbour_type'] ) );
		$this->assertSame( 'sd_neighbour_tax', $wpdb->get_var( $wpdb->prepare( "SELECT taxonomy FROM {$wpdb->term_taxonomy} WHERE term_id = %d", $ids['neighbour_term'] ) ) );
	}

	public function test_reverse_restores_the_old_names(): void {
		global $wpdb;
		Prefix_Migration::run();
		Prefix_Migration::run( true );
		wp_cache_flush();
		$ids = $this->ids;

		$this->assertSame( 'sd_donation', get_post_type( $ids['donation'] ) );
		$this->assertSame( '50', get_post_meta( $ids['donation'], '_sd_amount', true ) );
		$this->assertSame( '123', (string) get_option( 'sd_donation_product_id' ) );
		$this->assertSame( [ 'enabled' => 'no' ], get_option( 'woocommerce_sd_donation_receipt_settings' ) );
		$this->assertSame( 'sd_campaign', $wpdb->get_var( $wpdb->prepare( "SELECT taxonomy FROM {$wpdb->term_taxonomy} WHERE term_id = %d", $ids['term'] ) ) );
		$this->assertSame( 'sd_donation', $wpdb->get_var( "SELECT object_type FROM `{$wpdb->prefix}sd_activity_log` WHERE event_type = 'donation_created'" ) );
		$this->assertStringContainsString( '"postType":"sd_memorial"', get_post_field( 'post_content', $ids['page'] ) );
		// 2.x reads the flat family keys first, so the expanded ones serve it too.
		$this->assertSame( 'family@example.test', get_post_meta( $ids['memorial'], '_sd_notify_family_email', true ) );
	}

	public function test_maybe_run_records_the_version_and_a_report(): void {
		delete_option( Prefix_Migration::VERSION_OPTION );

		$this->assertTrue( Prefix_Migration::maybe_run() );
		$this->assertSame( Prefix_Migration::VERSION, get_option( Prefix_Migration::VERSION_OPTION ) );
		$report = get_option( Prefix_Migration::REPORT_OPTION );
		$this->assertTrue( $report['notice'] );
		$this->assertGreaterThan( 0, $report['counts']['post types'] );
	}

	public function test_hold_keeps_the_plugin_inert(): void {
		update_option( Prefix_Migration::HOLD_OPTION, time() );
		$this->assertFalse( Prefix_Migration::maybe_run() );
		$this->assertSame( 'sd_donation', get_post_type( $this->ids['donation'] ), 'Nothing moves while held.' );
	}

	public function test_old_action_names_reach_the_new_handlers(): void {
		$calls = 0;
		remove_all_actions( 'wp_ajax_skdon_view_receipt' );
		remove_all_actions( 'wp_ajax_sd_view_receipt' );
		add_action( 'wp_ajax_skdon_view_receipt', static function () use ( &$calls ): void { ++$calls; } );

		Prefix_Migration::register_action_aliases();
		do_action( 'wp_ajax_sd_view_receipt' );

		$this->assertSame( 1, $calls );
		$this->assertFalse( has_action( 'wp_ajax_nopriv_sd_view_receipt' ), 'Only hooks with a handler get an alias.' );
	}

	// ─── Fixtures ─────────────────────────────────────────────────────────

	private function seed_old_install(): void {
		global $wpdb;
		$f = self::factory();

		$donation     = $f->post->create( [ 'post_type' => 'sd_donation' ] );
		$old_donation = $f->post->create( [ 'post_type' => 'sd_donation' ] );
		$memorial     = $f->post->create( [ 'post_type' => 'sd_memorial' ] );
		$memorial2    = $f->post->create( [ 'post_type' => 'sd_memorial' ] );
		$user         = $f->user->create();

		add_post_meta( $donation, '_sd_amount', '50' );
		add_post_meta( $donation, '_sd_allocation', 'medical-care' );
		add_post_meta( $donation, '_sd_donation_date', '2025-03-01 10:00:00' );
		add_post_meta( $donation, '_sd_date', '2025-02-01 00:00:00' ); // Stale copy.
		add_post_meta( $old_donation, '_sd_date', '2024-12-24 09:00:00' ); // Copy only.

		add_post_meta( $memorial, '_sd_notify_family', [ 'enabled' => true, 'name' => 'The Smiths', 'email' => 'family@example.test', 'address' => '', 'send_card' => false ] );
		add_post_meta( $memorial2, '_sd_notify_family', [ 'enabled' => true, 'name' => 'Old Name', 'email' => 'old@example.test' ] );
		add_post_meta( $memorial2, '_sd_notify_family_email', 'later@example.test' );

		// Campaign term, its goal, its relationship, and its children option.
		$wpdb->insert( $wpdb->terms, [ 'name' => 'Spring Drive', 'slug' => 'spring-drive' ] );
		$term = (int) $wpdb->insert_id;
		$wpdb->insert( $wpdb->term_taxonomy, [ 'term_id' => $term, 'taxonomy' => 'sd_campaign', 'count' => 1 ] );
		$wpdb->insert( $wpdb->term_relationships, [ 'object_id' => $donation, 'term_taxonomy_id' => (int) $wpdb->insert_id ] );
		add_metadata( 'term', $term, '_sd_goal', '500' );
		update_option( 'sd_campaign_children', [ 7 ] );

		$logo = $f->attachment->create( [ 'post_mime_type' => 'image/png' ] );
		add_post_meta( $logo, '_sd_logo_status', 'approved' );
		$variation = $f->post->create( [ 'post_type' => 'product_variation' ] );
		add_post_meta( $variation, '_sd_allocation', 'facility' );

		$menu_item = $f->post->create( [ 'post_type' => 'nav_menu_item' ] );
		add_post_meta( $menu_item, '_menu_item_object', 'sd_memorial' );

		$template = $f->post->create( [ 'post_type' => 'wp_template', 'post_name' => 'single-sd_memorial' ] );
		$page     = $f->post->create( [
			'post_type'    => 'page',
			'post_content' => '<!-- wp:query {"query":{"postType":"sd_memorial","taxQuery":{"sd_memorial_year":[3]}}} --><p>Lists the sd_memorial post type.</p><!-- /wp:query -->',
		] );

		update_option( 'sd_donation_product_id', 123 );
		update_option( 'sd_config_settings_allocations', [ 'bedding' => 'Bedding' ] );
		update_option( 'woocommerce_sd_donation_receipt_settings', [ 'enabled' => 'no' ] );

		update_user_meta( $user, '_sd_candles', [ $memorial ] );
		update_user_option( $user, 'sd_dashboard_period', 'year' );
		update_user_meta( $user, 'metaboxhidden_sd_membership', [ 'sd_admin_notes' ] );

		$wpdb->insert( $wpdb->prefix . 'sd_activity_log', [
			'event_type'     => 'donation_created',
			'event_category' => 'donation',
			'message'        => 'A donated $50.00',
			'object_type'    => 'sd_donation',
			'object_id'      => $donation,
			'created_at'     => '2026-01-01 00:00:00',
		] );

		wp_schedule_event( time(), 'daily', 'sd_cleanup_activity_log' );
		set_transient( 'sd_menu_pending_count', 3 );
		set_transient( 'sd_donor_id_5', 9 );

		$this->ids = compact( 'donation', 'old_donation', 'memorial', 'user', 'term', 'menu_item', 'template', 'page', 'logo', 'variation' );
		$this->ids['memorial_flat'] = $memorial2;
	}

	/** Another plugin that happens to use sd_. */
	private function seed_neighbours(): void {
		global $wpdb;
		$f = self::factory();

		// Keys that are on the plugin's own list, on objects it does not own.
		$post = $f->post->create();
		add_post_meta( $post, '_sd_neighbour', 'keep' );
		add_post_meta( $post, '_sd_email', 'keep' );
		add_post_meta( $post, '_sd_date', '2001-01-01' );
		$attachment = $f->attachment->create( [ 'post_mime_type' => 'image/png' ] );
		add_post_meta( $attachment, '_sd_amount', 'keep' );
		$product = $f->post->create( [ 'post_type' => 'product' ] );
		add_post_meta( $product, '_sd_amount', 'keep' );
		update_user_meta( $this->ids['user'], '_sd_neighbour', 'keep' );
		update_option( 'sd_neighbour_setting', 'keep' );
		set_transient( 'sd_neighbour_cache', 'keep' );

		$type = $f->post->create( [ 'post_type' => 'sd_neighbour' ] );
		$wpdb->insert( $wpdb->terms, [ 'name' => 'Theirs', 'slug' => 'theirs' ] );
		$term = (int) $wpdb->insert_id;
		$wpdb->insert( $wpdb->term_taxonomy, [ 'term_id' => $term, 'taxonomy' => 'sd_neighbour_tax' ] );
		add_metadata( 'term', $term, '_sd_goal', 'keep' );
		add_post_meta( $type, '_sd_amount', 'keep' );

		$this->ids['neighbour_attachment'] = $attachment;
		$this->ids['neighbour_product']    = $product;
		$this->ids['neighbour_post'] = $post;
		$this->ids['neighbour_type'] = $type;
		$this->ids['neighbour_term'] = $term;
	}

	private function log_table_sql( string $table ): string {
		return "CREATE TABLE `{$table}` (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_type varchar(50) NOT NULL,
			event_category varchar(30) NOT NULL,
			message text NOT NULL,
			object_type varchar(30) DEFAULT NULL,
			object_id bigint(20) unsigned DEFAULT NULL,
			user_id bigint(20) unsigned DEFAULT NULL,
			ip_address varchar(45) DEFAULT NULL,
			meta longtext DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY (id)
		)";
	}

	private function table_exists( string $table ): bool {
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		$result   = $wpdb->query( "SELECT 1 FROM `{$table}` LIMIT 0" );
		$wpdb->suppress_errors( $suppress );
		return false !== $result;
	}
}
