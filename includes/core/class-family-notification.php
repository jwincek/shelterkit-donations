<?php
/**
 * Telling a memorial's family about the gift (#58).
 *
 * A donor can ask, at checkout or on a check staff enter with Quick Add,
 * for the honoree's family to be told. The Memorial Family Notification
 * email does that. Until #58 it never went out, from any path:
 *
 * - its condition read a field nothing stored, so it was always false
 * - its template called a helper with the wrong arguments
 * - the admin's Notify Family action fired a hook no email listened to,
 *   yet recorded the family as notified
 *
 * This class sends it on request, records a family as notified only when
 * the email has actually gone (from checkout or from staff), and repairs
 * the dates the old Notify Family action recorded without sending anything.
 *
 * @package Starter_Shelter
 * @subpackage Core
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Core;

use Starter_Shelter\Admin\Activity_Log;
use Starter_Shelter\Helpers;

/**
 * The family notification: sending it, recording it, and the repair.
 */
final class Family_Notification {

	public const EMAIL_ID = 'memorial-family-notification';

	/** Set once the old action's dates have been cleared. */
	public const REPAIR_OPTION = 'skdon_family_notified_repaired';

	/** How many memorials the repair offered again, until the notice is dismissed. */
	public const NOTICE_OPTION = 'skdon_family_notified_reoffered';

	private const DATE_KEY = '_skdon_family_notified_date';

	public static function init(): void {
		add_action( 'starter_shelter_email_sent', [ self::class, 'record_sent' ], 5, 3 );
		add_action( 'init', [ self::class, 'maybe_repair' ], 1 );
		add_action( 'admin_notices', [ self::class, 'repair_notice' ] );
		add_action( 'admin_post_skdon_dismiss_family_notice', [ self::class, 'dismiss_notice' ] );
	}

	/**
	 * Email a memorial's family now.
	 *
	 * Whether it was sent is recorded by record_sent(), which hears every
	 * send of this email, here and at checkout alike.
	 *
	 * @param int $memorial_id Memorial post ID.
	 * @return bool|\WP_Error True when sent, or why it wasn't.
	 */
	public static function send( int $memorial_id ): bool|\WP_Error {
		$notify = Helpers\get_memorial_notify_family( $memorial_id );
		if ( ! $notify['enabled'] || ! is_email( $notify['email'] ) ) {
			return new \WP_Error( 'no_family_email', __( 'The memorial has no family email address.', 'shelterkit-donations' ) );
		}
		return Helpers\send_memorial_email( self::EMAIL_ID, $memorial_id, (int) get_post_meta( $memorial_id, '_skdon_donor_id', true ) );
	}

	/**
	 * When the family email has gone out, from any path, record it.
	 *
	 * At checkout the email goes out by itself, when the memorial is created.
	 * Recording it there too is what stops Notify Family offering to email
	 * the same family again.
	 *
	 * @param string $email_id  Which email was sent.
	 * @param string $recipient Its address.
	 * @param int    $object_id The memorial, for this email.
	 */
	public static function record_sent( $email_id, $recipient, $object_id ): void {
		if ( self::EMAIL_ID !== $email_id || (int) $object_id <= 0 ) {
			return;
		}
		update_post_meta( (int) $object_id, self::DATE_KEY, current_time( 'mysql' ) );
		do_action( 'starter_shelter_family_notified', (int) $object_id, (string) $recipient );
	}

	/**
	 * Clear the notified dates the old Notify Family action recorded, once.
	 *
	 * Before #58 only that action wrote the date, and it never sent the
	 * email. So every date stored before this update says a family was
	 * emailed when they weren't. Clearing it puts the memorial back in the
	 * Memorials list's "pending" view, with Notify Family offered again.
	 *
	 * Runs early on every request, so it is done before anything new can
	 * send the email and record a real date.
	 */
	public static function maybe_repair(): void {
		if ( get_option( self::REPAIR_OPTION ) ) {
			return;
		}

		$ids = get_posts( [
			'post_type'   => 'skdon_memorial',
			'post_status' => 'any',
			'numberposts' => -1,
			'fields'      => 'ids',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- once, on update.
			'meta_query'  => [ [ 'key' => self::DATE_KEY, 'value' => '', 'compare' => '!=' ] ],
		] );
		foreach ( $ids as $id ) {
			delete_post_meta( (int) $id, self::DATE_KEY );
		}

		update_option( self::REPAIR_OPTION, 1 );
		if ( $ids ) {
			update_option( self::NOTICE_OPTION, count( $ids ), false );
			Activity_Log::record( 'family_notified_reset', 'admin', [ 'count' => count( $ids ) ] );
		}
	}

	/**
	 * Say how many memorials are offered Notify Family again, until dismissed.
	 */
	public static function repair_notice(): void {
		$count = (int) get_option( self::NOTICE_OPTION, 0 );
		if ( $count < 1 || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! ( 'dashboard' === $screen->id
			|| str_contains( (string) $screen->id, 'shelterkit-donations' )
			|| 'skdon_memorial' === $screen->post_type ) ) {
			return;
		}

		$pending = admin_url( 'edit.php?post_type=skdon_memorial&notify_status=pending' );
		$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=skdon_dismiss_family_notice' ), 'skdon_dismiss_family_notice' );
		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'ShelterKit Donations:', 'shelterkit-donations' ); ?></strong>
				<?php
				echo esc_html( sprintf(
					/* translators: %d: number of memorials. */
					_n(
						'%d memorial was marked “family notified”, but until this update Notify Family never actually emailed the family. It is back among the memorials waiting to notify the family.',
						'%d memorials were marked “family notified”, but until this update Notify Family never actually emailed the family. They are back among the memorials waiting to notify the family.',
						$count,
						'shelterkit-donations'
					),
					$count
				) );
				?>
			</p>
			<p>
				<a href="<?php echo esc_url( $pending ); ?>"><?php esc_html_e( 'See memorials waiting to notify the family', 'shelterkit-donations' ); ?></a>
				<a href="<?php echo esc_url( $dismiss ); ?>" style="margin-left: 12px;"><?php esc_html_e( 'Dismiss', 'shelterkit-donations' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Dismiss the repair notice.
	 */
	public static function dismiss_notice(): void {
		check_admin_referer( 'skdon_dismiss_family_notice' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'shelterkit-donations' ), 403 );
		}
		delete_option( self::NOTICE_OPTION );
		wp_safe_redirect( wp_get_referer() ?: admin_url() );
		exit;
	}
}
