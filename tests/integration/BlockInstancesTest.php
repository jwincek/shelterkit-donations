<?php
/**
 * Two of the same block on one page each keep their own figures (#64).
 *
 * The Campaign Card and Donor Stats blocks put their figures in shared
 * Interactivity state. The server rendered each block right, but the page
 * sends the state once, as the last block left it, so once the page loaded
 * every card showed the last card's progress, total and donors. Each block's
 * figures are now in its own context.
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Tests\Integration;

use WP_HTML_Tag_Processor;
use WP_UnitTestCase;

final class BlockInstancesTest extends WP_UnitTestCase {

	/** @return array<int, array> Each interactive root's context, in order. */
	private function contexts( string $html, string $namespace ): array {
		$out  = [];
		$tags = new WP_HTML_Tag_Processor( $html );
		while ( $tags->next_tag() ) {
			if ( $namespace === $tags->get_attribute( 'data-wp-interactive' ) ) {
				$out[] = json_decode( (string) $tags->get_attribute( 'data-wp-context' ), true );
			}
		}
		return $out;
	}

	private function campaign( string $name, float $goal, array $gifts ): int {
		$term = wp_insert_term( $name, 'skdon_campaign' );
		$id   = (int) $term['term_id'];
		update_term_meta( $id, '_skdon_campaign_type', 'donation_drive' );
		update_term_meta( $id, '_skdon_goal', $goal );
		foreach ( $gifts as $amount ) {
			$gift = self::factory()->post->create( [ 'post_type' => 'skdon_donation', 'post_status' => 'publish' ] );
			update_post_meta( $gift, '_skdon_amount', $amount );
			wp_set_object_terms( $gift, [ $id ], 'skdon_campaign' );
		}
		return $id;
	}

	public function test_each_campaign_card_keeps_its_own_figures(): void {
		$winter  = $this->campaign( 'Winter Warmth', 1000, [ 300, 200 ] );
		$kittens = $this->campaign( 'Kitten Season', 1000, [ 100 ] );

		$html = do_blocks(
			'<!-- wp:shelter-donations/campaign-card {"campaignId":' . $winter . '} /-->'
			. '<!-- wp:shelter-donations/campaign-card {"campaignId":' . $kittens . '} /-->'
		);
		[ $first, $second ] = $this->contexts( $html, 'shelter-donations/campaign-card' );

		$this->assertSame( $winter, $first['campaign']['id'] );
		$this->assertSame( '50%', $first['progressText'] );
		$this->assertSame( '10%', $second['progressText'], 'Each card has its own progress…' );
		$this->assertNotSame( $first['campaign']['raised_formatted'], $second['campaign']['raised_formatted'], '…and its own total.' );

		$state = wp_interactivity_state( 'shelter-donations/campaign-card' );
		$this->assertArrayNotHasKey( 'campaign', $state, 'Nothing shared for the last card to overwrite.' );
		$this->assertStringNotContainsString( 'state.campaign', $html );
	}

	public function test_each_donor_stats_block_keeps_its_own_figures(): void {
		$html = do_blocks(
			'<!-- wp:shelter-donations/donor-stats {"period":"this_year","animateNumbers":true} /-->'
			. '<!-- wp:shelter-donations/donor-stats {"period":"all_time","animateNumbers":true} /-->'
		);
		$contexts = $this->contexts( $html, 'shelter-donations/donor-stats' );

		$this->assertCount( 2, $contexts );
		$this->assertSame( [ 'this_year', 'all_time' ], array_column( $contexts, 'period' ) );
		$this->assertArrayHasKey( 'formatted', $contexts[0] );
		$this->assertArrayNotHasKey( 'stats', wp_interactivity_state( 'shelter-donations/donor-stats' ) );
		$this->assertStringNotContainsString( 'state.formatted', $html );
	}

	public function test_the_editors_donor_search_is_styled(): void {
		\Starter_Shelter\Admin\Meta_Boxes::init(); // What wp-admin does.
		$this->assertSame( 20, has_action( 'admin_enqueue_scripts', [ \Starter_Shelter\Admin\Meta_Boxes::class, 'enqueue_assets' ] ), 'After WooCommerce registers its admin styles.' );
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/admin/class-meta-boxes.php' );
		$this->assertStringContainsString( "wp_enqueue_style( 'woocommerce_admin_styles' )", $source, 'select2\'s styles, when WooCommerce registers no separate select2 style.' );
	}
}
