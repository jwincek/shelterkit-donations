<?php
/**
 * Every starter_shelter_* action the plugin fires is heard by something.
 *
 * Notify Family fired starter_shelter_memorial_family_notification, which no
 * email listened to, so the family was never emailed though the screen said
 * they were (#58). The emails subscribe through the `trigger_hook` in their
 * manifest, so those count as listeners here. Hooks kept for other plugins
 * are listed with the reason.
 *
 * This is the config validator's check 4, run in CI. The validator reads
 * the email triggers from config/emails.json, which has been empty since
 * the emails moved into the manifests.
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ActionHooksTest extends TestCase {

	/** Fired for other plugins to use; nothing in this plugin needs them. */
	private const FOR_OTHER_PLUGINS = [
		'starter_shelter_legacy_record_updated',
		'starter_shelter_legacy_order_synced',
		'starter_shelter_donor_address_updated',
		'starter_shelter_donor_profile_updated',
		'starter_shelter_renewal_reminders_processed',
		'starter_shelter_renewal_reminder_sent',
	];

	public function test_every_action_fired_has_a_listener(): void {
		$root      = dirname( __DIR__, 2 ) . '/';
		$fired     = [];
		$listened  = [];

		foreach ( glob( $root . 'config/manifests/*.php' ) as $manifest ) {
			preg_match_all( '/[\'"]trigger_hook[\'"]\s*=>\s*[\'"](starter_shelter_[a-z0-9_]+)[\'"]/', (string) file_get_contents( $manifest ), $m );
			foreach ( $m[1] as $hook ) {
				$listened[ $hook ] = true;
			}
		}

		foreach ( $this->files( $root ) as $file ) {
			$source   = (string) file_get_contents( $file );
			$relative = substr( $file, strlen( $root ) );
			preg_match_all( '/\bdo_action\(\s*[\'"](starter_shelter_[a-z0-9_]+)[\'"]/', $source, $m, PREG_OFFSET_CAPTURE );
			foreach ( $m[1] as [ $hook, $offset ] ) {
				$fired[ $hook ][] = $relative . ':' . ( substr_count( substr( $source, 0, $offset ), "\n" ) + 1 );
			}
			preg_match_all( '/\badd_action\(\s*[\'"](starter_shelter_[a-z0-9_]+)[\'"]/', $source, $m );
			foreach ( $m[1] as $hook ) {
				$listened[ $hook ] = true;
			}
		}

		$this->assertGreaterThan( 20, count( $fired ), 'The scan should find the plugin\'s actions.' );

		$unheard = [];
		foreach ( $fired as $hook => $where ) {
			if ( isset( $listened[ $hook ] ) || in_array( $hook, self::FOR_OTHER_PLUGINS, true )
				|| str_ends_with( $hook, '_email_footer' ) || str_ends_with( $hook, '_email_content' ) ) {
				continue;
			}
			$unheard[] = $hook . ' (fired at ' . implode( ', ', $where ) . ')';
		}
		$this->assertSame( [], $unheard, 'Nothing listens to these actions, so whatever the code firing them expects to happen doesn\'t.' );
	}

	/** @return string[] */
	private function files( string $root ): array {
		$files = [ $root . 'shelterkit-donations.php' ];
		foreach ( [ 'includes', 'blocks', 'templates' ] as $dir ) {
			$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . $dir, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $iterator as $file ) {
				if ( str_ends_with( $file->getPathname(), '.php' ) ) {
					$files[] = $file->getPathname();
				}
			}
		}
		return $files;
	}
}
