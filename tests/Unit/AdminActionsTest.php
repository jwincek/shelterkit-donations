<?php
/**
 * Every AJAX or admin-post action the plugin sends must have a handler.
 *
 * #31 and #32 were both a request to an action nothing handled: Data Tools'
 * Reset Sync Flags posted one, and the Memberships Export Selected redirected
 * to another. admin-ajax answers that with a bare 400 "0", so the button
 * "works" in code review and fails for the user. This scans what the scripts,
 * URLs and forms send, and what PHP registers, and compares the two.
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AdminActionsTest extends TestCase {

	/** Where an action name is sent from. Only the plugin's own (skdon_) names. */
	private const SENT = [
		'/\baction\s*:\s*[\'"](skdon_[a-z0-9_]+)[\'"]/',                 // { action: 'x' }
		'/[\'"]action[\'"]\s*(?:,|=>)\s*[\'"](skdon_[a-z0-9_]+)[\'"]/',   // append( 'action', 'x' ), 'action' => 'x'
		'/\baction=(skdon_[a-z0-9_]+)/',                                  // admin-ajax.php?action=x
		'/name=[\'"]action[\'"]\s+value=[\'"](skdon_[a-z0-9_]+)[\'"]/',   // <input name="action" value="x">
		'/\bpost\(\s*[\'"](skdon_[a-z0-9_]+)[\'"]/',                      // Data Tools' post( 'x', … ) helper
	];

	private const REGISTERED = '/add_action\(\s*[\'"](?:wp_ajax_nopriv_|wp_ajax_|admin_post_nopriv_|admin_post_)(skdon_[a-z0-9_]+)[\'"]/';

	public function test_every_action_sent_has_a_handler(): void {
		$root       = dirname( __DIR__, 2 ) . '/';
		$sent       = [];
		$registered = [];

		foreach ( $this->files( $root ) as $file ) {
			$source   = (string) file_get_contents( $file );
			$relative = substr( $file, strlen( $root ) );
			foreach ( self::SENT as $pattern ) {
				preg_match_all( $pattern, $source, $matches, PREG_OFFSET_CAPTURE );
				foreach ( $matches[1] as [ $name, $offset ] ) {
					$sent[ $name ][] = $relative . ':' . ( substr_count( substr( $source, 0, $offset ), "\n" ) + 1 );
				}
			}
			if ( str_ends_with( $file, '.php' ) && preg_match_all( self::REGISTERED, $source, $matches ) ) {
				foreach ( $matches[1] as $name ) {
					$registered[ $name ] = true;
				}
			}
		}

		// Guard the guard: a broken pattern would otherwise pass vacuously.
		$this->assertGreaterThan( 25, count( $sent ), 'The scan should find the plugin\'s actions.' );

		$missing = [];
		foreach ( $sent as $name => $where ) {
			if ( ! isset( $registered[ $name ] ) ) {
				$missing[] = $name . ' (sent from ' . implode( ', ', array_unique( $where ) ) . ')';
			}
		}
		$this->assertSame( [], $missing, 'Each of these actions is sent, but no wp_ajax_ / admin_post_ handler is registered for it.' );
	}

	/** @return string[] */
	private function files( string $root ): array {
		$files = [ $root . 'shelterkit-donations.php' ];
		foreach ( [ 'includes', 'blocks', 'assets/js', 'templates' ] as $dir ) {
			$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . $dir, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $iterator as $file ) {
				$path = $file->getPathname();
				if ( preg_match( '/\.(php|js)$/', $path ) && ! str_contains( $path, '/build/' ) ) {
					$files[] = $path;
				}
			}
		}
		return $files;
	}
}
