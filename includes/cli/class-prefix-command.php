<?php
/**
 * WP-CLI: `wp shelterkit-donations migrate-prefix`.
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Cli;

use Starter_Shelter\Core\Prefix_Migration;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Inspect, re-run or reverse the move of stored data from `sd_` to `skdon_` (#30).
 */
class Prefix_Command {

	/**
	 * Move the plugin's stored data to the `skdon_` names, or back.
	 *
	 * The move normally runs by itself on the first request after an
	 * upgrade. Use this to check it, to finish one that stopped, or to move
	 * the data back before reinstalling a 2.x release.
	 *
	 * ## OPTIONS
	 *
	 * [--status]
	 * : Report what is still stored under an old name, and the last run's
	 * result. Exits non-zero if anything is left.
	 *
	 * [--reverse]
	 * : Move the data back to the `sd_` names for a rollback to 2.x. The
	 * plugin then stays inert until it is replaced with a 2.x release, or
	 * until this command runs again without --reverse.
	 *
	 * [--yes]
	 * : Skip the confirmation for --reverse.
	 *
	 * ## EXAMPLES
	 *
	 *     wp shelterkit-donations migrate-prefix --status
	 *     wp shelterkit-donations migrate-prefix
	 *     wp shelterkit-donations migrate-prefix --reverse --yes
	 *
	 * @param array<int, string>    $args       Positional args.
	 * @param array<string, string> $assoc_args Flag args.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		if ( ! empty( $assoc_args['status'] ) ) {
			$this->status();
			return;
		}

		if ( ! empty( $assoc_args['reverse'] ) ) {
			WP_CLI::confirm( 'Move ShelterKit Donations data back to the sd_ names? The plugin stays inert until a 2.x release replaces it.', $assoc_args );
			$counts = Prefix_Migration::run( true );
			delete_option( Prefix_Migration::VERSION_OPTION );
			update_option( Prefix_Migration::HOLD_OPTION, time(), true );
			$this->print_counts( $counts );
			WP_CLI::success( 'Moved back to the sd_ names. Replace the plugin with a 2.x release now.' );
			return;
		}

		delete_option( Prefix_Migration::HOLD_OPTION );
		delete_option( Prefix_Migration::VERSION_OPTION );
		if ( ! Prefix_Migration::maybe_run() ) {
			$report = get_option( Prefix_Migration::REPORT_OPTION );
			WP_CLI::error( 'The move did not complete: ' . ( is_array( $report ) ? (string) ( $report['error'] ?? 'another request holds the lock' ) : 'unknown error' ) );
		}
		$report = get_option( Prefix_Migration::REPORT_OPTION );
		$this->print_counts( is_array( $report ) ? (array) ( $report['counts'] ?? [] ) : [] );
		WP_CLI::success( 'Stored data is under the skdon_ names.' );
	}

	/**
	 * Print where old names remain, and the last run.
	 */
	private function status(): void {
		$report = get_option( Prefix_Migration::REPORT_OPTION );
		WP_CLI::log( 'Version: ' . ( get_option( Prefix_Migration::VERSION_OPTION ) ?: 'not migrated' ) );
		if ( get_option( Prefix_Migration::HOLD_OPTION ) ) {
			WP_CLI::log( 'Held: data was moved back for a rollback; the plugin is inert.' );
		}
		if ( is_array( $report ) ) {
			WP_CLI::log( 'Last run: ' . gmdate( 'Y-m-d H:i:s', (int) ( $report['time'] ?? 0 ) ) . ' UTC' );
			if ( ! empty( $report['error'] ) ) {
				WP_CLI::log( 'Error: ' . $report['error'] );
			}
			$this->print_counts( (array) ( $report['counts'] ?? [] ) );
		}

		$left = Prefix_Migration::leftovers();
		if ( $left ) {
			foreach ( $left as $where => $count ) {
				WP_CLI::log( sprintf( 'Still under an sd_ name: %s %d', $where, $count ) );
			}
			WP_CLI::halt( 1 );
		}
		WP_CLI::success( 'Nothing is stored under an sd_ name.' );
	}

	/**
	 * Print per-step counts.
	 *
	 * @param array<string, int> $counts Counts.
	 */
	private function print_counts( array $counts ): void {
		foreach ( $counts as $step => $count ) {
			WP_CLI::log( sprintf( '  %-24s %d', $step, $count ) );
		}
	}
}
