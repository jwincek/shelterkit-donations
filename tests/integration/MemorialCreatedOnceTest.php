<?php
/**
 * A memorial is "created" once (#53).
 *
 * The memorial ability inserted the post, which ran the save_post handler's
 * post-processing for a new memorial, and then ran the same post-processing
 * itself. Every memorial fired starter_shelter_memorial_created twice, so the
 * donor got two confirmation emails and their lifetime giving counted the
 * gift twice. The legacy memorial import counted it twice too. And a memorial
 * added in wp-admin fired "created" for its empty auto-draft, and never when
 * it was published.
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Tests\Integration;

use Starter_Shelter\Admin\Import_Export\Legacy_Memorial_Parser;
use WP_UnitTestCase;

use function Starter_Shelter\Abilities\Memorials\create as create_memorial;

final class MemorialCreatedOnceTest extends WP_UnitTestCase {

	/** @var array<int, array> Each starter_shelter_memorial_created call: [ memorial, donor, input ]. */
	private array $created = [];

	public function set_up(): void {
		parent::set_up();
		// The abilities' Provider loads this on wp_abilities_api_init.
		require_once dirname( __DIR__, 2 ) . '/includes/abilities/memorials.php';
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->created = [];
		add_action( 'starter_shelter_memorial_created', [ $this, 'note' ], 10, 3 );
	}

	public function tear_down(): void {
		remove_action( 'starter_shelter_memorial_created', [ $this, 'note' ], 10 );
		$_POST = [];
		parent::tear_down();
	}

	public function note( $memorial_id, $donor_id, $input ): void {
		// Whether emails would go: Config_Email sends nothing during internal processing.
		$this->created[] = [ (int) $memorial_id, (int) $donor_id, $input, ! \Starter_Shelter\Helpers\is_internal_processing() ];
	}

	private function donor(): int {
		$donor = self::factory()->post->create( [ 'post_type' => 'skdon_donor', 'post_status' => 'publish', 'post_title' => 'Pat Donor' ] );
		update_post_meta( $donor, '_skdon_email', 'pat@example.test' );
		update_post_meta( $donor, '_skdon_display_name', 'Pat Donor' );
		return $donor;
	}

	public function test_a_memorial_from_the_ability_is_created_once_and_counted_once(): void {
		$donor  = $this->donor();
		$result = create_memorial( [
			'donor_id'      => $donor,
			'honoree_name'  => 'Biscuit',
			'memorial_type' => 'pet',
			'amount'        => 25,
		] );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $this->created, 'One "created", so one confirmation email.' );
		$this->assertSame( 'Biscuit', $this->created[0][2]['honoree_name'] );
		$this->assertTrue( $this->created[0][3], 'A gift bought at checkout is confirmed by email.' );
		$this->assertSame( 25.0, (float) get_post_meta( $donor, '_skdon_lifetime_giving', true ), 'The gift counts once toward lifetime giving.' );
	}

	public function test_a_legacy_import_counts_each_memorial_once(): void {
		$file = wp_tempnam( 'memorials.csv' );
		file_put_contents( $file, "In Memory Of,By\nMarch\nBiscuit,Robin Quayle\n" );

		$results = Legacy_Memorial_Parser::import( $file, 2025, true, 10.0 );
		wp_delete_file( $file );

		$this->assertSame( 1, $results['created'] );
		$memorial = (int) $results['details'][0]['memorial_id'];
		$donor    = (int) get_post_meta( $memorial, '_skdon_donor_id', true );
		$this->assertSame( 10.0, (float) get_post_meta( $donor, '_skdon_lifetime_giving', true ), 'Counted once.' );
		$this->assertCount( 1, $this->created, 'And "created" once, so the log has it…' );
		$this->assertFalse( $this->created[0][3], '…without emailing anyone about a memorial from history.' );
	}

	public function test_a_memorial_added_in_the_admin_is_created_when_published_not_as_a_draft(): void {
		$donor = $this->donor();

		// Add New: WordPress inserts an empty auto-draft first.
		$memorial = wp_insert_post( [ 'post_type' => 'skdon_memorial', 'post_status' => 'auto-draft', 'post_title' => 'Auto Draft' ] );
		$this->assertSame( [], $this->created, 'Nothing is created yet: it is an empty draft.' );
		\Starter_Shelter\Admin\Record_Edit_Tracker::reset(); // Publishing is a later request.
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}skdon_activity_log" );

		// Publish from the editor: the meta boxes save, then the post.
		$_POST = [ 'post_ID' => (string) $memorial, 'action' => 'editpost' ];
		update_post_meta( $memorial, '_skdon_honoree_name', 'Maggie' );
		update_post_meta( $memorial, '_skdon_donor_id', $donor );
		update_post_meta( $memorial, '_skdon_amount', 40 );
		wp_update_post( [ 'ID' => $memorial, 'post_status' => 'publish', 'post_title' => 'Maggie' ] );

		$this->assertCount( 1, $this->created, 'Publishing it creates it, once…' );
		$this->assertSame( 'Maggie', $this->created[0][2]['honoree_name'], '…with what the admin entered…' );
		$this->assertFalse( $this->created[0][3], '…and emails no one by itself, as before.' );
		$this->assertSame( 40.0, (float) get_post_meta( $donor, '_skdon_lifetime_giving', true ) );
		\Starter_Shelter\Admin\Record_Edit_Tracker::flush();
		$this->assertSame(
			[ 'memorial_created' ],
			$wpdb->get_col( "SELECT event_type FROM {$wpdb->prefix}skdon_activity_log WHERE object_id = {$memorial}" ),
			'One entry: its creation, not also its fields being filled in as an edit.'
		);

		// Saving it again in the editor is an edit, not a new memorial.
		wp_update_post( [ 'ID' => $memorial, 'post_title' => 'Maggie' ] );
		$this->assertCount( 1, $this->created );
	}
}
