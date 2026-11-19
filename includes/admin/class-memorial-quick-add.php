<?php
/**
 * Memorial Quick Add: enter one or more memorial gifts received by mail or
 * in person, on one screen.
 *
 * Reached from a Quick Add button beside Add New on the Memorials list. Each
 * memorial is created with the same ability checkout uses, so it counts
 * toward the donor's lifetime giving, is filed under its year and appears
 * on the memorial wall. No WooCommerce order is created. Nobody is emailed
 * unless staff tick it: the donor's confirmation and the family's
 * notification each have their own checkbox, off by default.
 *
 * @package Starter_Shelter
 * @subpackage Admin
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Admin;

use Starter_Shelter\Admin\Shared\Donor_Lookup;
use Starter_Shelter\Core\Config;
use Starter_Shelter\Core\Family_Notification;
use Starter_Shelter\Helpers;

/**
 * The Quick Add screen, its donor search and its submission.
 */
final class Memorial_Quick_Add {

	public const PAGE          = 'shelterkit-donations-quick-add';
	public const SEARCH_ACTION = 'skdon_quick_add_donor_search';
	private const NONCE        = 'skdon_quick_add_memorials';
	private const SEARCH_NONCE = 'skdon_quick_add_donor_search';
	private const CAP          = 'manage_options';
	private const POST_TYPE    = 'skdon_memorial';
	private const MAX_ROWS     = 50;

	/**
	 * A submission that failed validation: its rows and errors, shown again.
	 *
	 * @var array{rows: array<int, array>, errors: array<int, array<string, string>>, message?: string}|null
	 */
	private static ?array $state = null;

	public static function init(): void {
		add_action( 'admin_menu', [ self::class, 'register_page' ] );
		add_action( 'load-edit.php', [ self::class, 'add_list_button' ] );
		add_action( 'wp_ajax_skdon_quick_add_donor_search', [ self::class, 'ajax_donor_search' ] );
		add_filter( 'parent_file', [ self::class, 'parent_file' ] );
		add_filter( 'submenu_file', [ self::class, 'submenu_file' ] );
	}

	public static function url( array $args = [] ): string {
		return add_query_arg( array_merge( [ 'page' => self::PAGE ], $args ), admin_url( 'admin.php' ) );
	}

	// ─── Screen ────────────────────────────────────────────────────

	/**
	 * A page with no menu entry of its own: the Memorials list links to it.
	 */
	public static function register_page(): void {
		$hook = add_submenu_page(
			'',
			__( 'Quick Add Memorials', 'shelterkit-donations' ),
			__( 'Quick Add Memorials', 'shelterkit-donations' ),
			self::CAP,
			self::PAGE,
			[ self::class, 'render_page' ]
		);
		if ( $hook ) {
			add_action( 'load-' . $hook, [ self::class, 'load' ] );
		}
	}

	private static function on_page(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which screen this is, not an action.
		return isset( $_GET['page'] ) && self::PAGE === $_GET['page'];
	}

	/** Keep ShelterKit Donations → Memorials highlighted in the menu. */
	public static function parent_file( $parent_file ) {
		return self::on_page() ? Menu::MENU_SLUG : $parent_file;
	}

	public static function submenu_file( $submenu_file ) {
		return self::on_page() ? 'edit.php?post_type=' . self::POST_TYPE : $submenu_file;
	}

	/**
	 * A Quick Add button beside Add New on the Memorials list.
	 *
	 * WordPress has no hook for the list's title actions, so a line of script
	 * places it, as core's own screens do for theirs.
	 */
	public static function add_list_button(): void {
		$screen = get_current_screen();
		if ( ! $screen || self::POST_TYPE !== $screen->post_type || 'edit' !== $screen->base || ! current_user_can( self::CAP ) ) {
			return;
		}
		add_action( 'admin_footer', static function (): void {
			wp_print_inline_script_tag( sprintf(
				'(function(){var a=document.querySelector(".wrap .page-title-action");if(!a)return;var b=document.createElement("a");b.href=%s;b.className="page-title-action sd-quick-add-button";b.textContent=%s;a.insertAdjacentElement("afterend",b);})();',
				wp_json_encode( self::url() ),
				wp_json_encode( __( 'Quick Add', 'shelterkit-donations' ) )
			) );
		} );
	}

	/**
	 * Before the screen renders: its assets, and a submission.
	 */
	public static function load(): void {
		global $title;
		$title = __( 'Quick Add Memorials', 'shelterkit-donations' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- a page with no menu entry has no title otherwise.

		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ], 20 );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked below, before anything is read.
		if ( ! isset( $_POST['skdon_quick_add'] ) ) {
			return;
		}
		check_admin_referer( self::NONCE );
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to add memorials.', 'shelterkit-donations' ), 403 );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parse_rows() sanitises each field by its type.
		$raw     = isset( $_POST['qa'] ) && is_array( $_POST['qa'] ) ? wp_unslash( $_POST['qa'] ) : [];
		$outcome = self::submit( $raw );

		if ( isset( $outcome['errors'] ) ) {
			self::$state = $outcome;
			return;
		}

		$token = wp_generate_password( 12, false );
		set_transient( 'skdon_quick_add_' . $token, [ 'user' => get_current_user_id(), 'results' => $outcome['results'] ], 10 * MINUTE_IN_SECONDS );
		wp_safe_redirect( self::url( [ 'added' => $token ] ) );
		exit;
	}

	public static function enqueue(): void {
		$select2 = wp_script_is( 'selectWoo', 'registered' ) ? 'selectWoo' : ( wp_script_is( 'select2', 'registered' ) ? 'select2' : '' );
		if ( $select2 ) {
			wp_enqueue_script( $select2 );
		}
		if ( wp_style_is( 'select2', 'registered' ) ) {
			wp_enqueue_style( 'select2' );
		} elseif ( wp_style_is( 'woocommerce_admin_styles', 'registered' ) ) {
			wp_enqueue_style( 'woocommerce_admin_styles' );
		}

		wp_enqueue_script(
			'skdon-quick-add',
			STARTER_SHELTER_URL . 'assets/js/admin-quick-add.js',
			array_values( array_filter( [ 'jquery', $select2 ] ) ),
			STARTER_SHELTER_VERSION,
			true
		);
		wp_localize_script( 'skdon-quick-add', 'sdQuickAdd', [
			'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
			'searchAction' => self::SEARCH_ACTION,
			'searchNonce'  => wp_create_nonce( self::SEARCH_NONCE ),
			'i18n'         => [
				'typeMore'  => __( 'Type 2 or more letters of a name or email', 'shelterkit-donations' ),
				'noResults' => __( 'No donor found. Enter a new donor below.', 'shelterkit-donations' ),
				'searching' => __( 'Searching…', 'shelterkit-donations' ),
			],
		] );

		wp_register_style( 'skdon-quick-add', false, [], STARTER_SHELTER_VERSION );
		wp_enqueue_style( 'skdon-quick-add' );
		wp_add_inline_style( 'skdon-quick-add', self::css() );
	}

	// ─── Submission ────────────────────────────────────────────────

	/**
	 * Validate every row and, only if all are valid, create them.
	 *
	 * All or nothing, so a stack of checks entered together is never half
	 * entered: one mistake means nothing is added, and the screen comes back
	 * with what was typed and what to fix.
	 *
	 * @param array $raw The posted rows.
	 * @return array{results: array}|array{rows: array, errors: array, message: string}
	 */
	public static function submit( array $raw ): array {
		$rows = self::parse_rows( $raw );
		if ( ! $rows ) {
			return [
				'rows'    => [],
				'errors'  => [],
				'message' => __( 'Enter at least one memorial.', 'shelterkit-donations' ),
			];
		}

		$errors = [];
		foreach ( $rows as $i => $row ) {
			$row_errors = self::validate( $row );
			if ( $row_errors ) {
				$errors[ $i ] = $row_errors;
			}
		}
		if ( $errors ) {
			return [
				'rows'    => $rows,
				'errors'  => $errors,
				'message' => __( 'Nothing was added. Fix the highlighted fields, then add them again.', 'shelterkit-donations' ),
			];
		}

		return [ 'results' => self::create_all( $rows ) ];
	}

	/**
	 * The posted rows, sanitised, without rows left blank.
	 *
	 * @param array $raw The posted rows.
	 * @return array<int, array> Rows, indexed from 0.
	 */
	public static function parse_rows( array $raw ): array {
		$text = static fn( array $r, string $key ): string => is_scalar( $r[ $key ] ?? null ) ? trim( (string) $r[ $key ] ) : '';
		$flag = static fn( array $r, string $key ): bool => ! empty( $r[ $key ] ) && is_scalar( $r[ $key ] );
		$id   = static fn( array $r, string $key ): int => max( 0, (int) ( is_scalar( $r[ $key ] ?? null ) ? $r[ $key ] : 0 ) );

		$rows = [];
		foreach ( array_slice( $raw, 0, self::MAX_ROWS ) as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$row = [
				'donor_id'            => $id( $r, 'donor_id' ),
				'donor_name'          => Donor_Lookup::sanitize_display_name( $text( $r, 'donor_name' ) ),
				'donor_email'         => sanitize_text_field( $text( $r, 'donor_email' ) ),
				'new_donor_confirmed' => $flag( $r, 'new_donor_confirmed' ),
				'is_anonymous'        => $flag( $r, 'is_anonymous' ),
				'dedication_type'     => 'honor' === $text( $r, 'dedication_type' ) ? 'honor' : 'memory',
				'honoree_name'        => sanitize_text_field( $text( $r, 'honoree_name' ) ),
				'memorial_type'       => sanitize_key( $text( $r, 'memorial_type' ) ?: 'person' ),
				'pet_species'         => sanitize_key( $text( $r, 'pet_species' ) ),
				'amount'              => sanitize_text_field( $text( $r, 'amount' ) ),
				'date'                => sanitize_text_field( $text( $r, 'date' ) ),
				'campaign_id'         => $id( $r, 'campaign_id' ),
				'tribute_message'     => sanitize_textarea_field( $text( $r, 'tribute_message' ) ),
				'notify_family'       => $flag( $r, 'notify_family' ),
				'family_name'         => sanitize_text_field( $text( $r, 'family_name' ) ),
				'family_email'        => sanitize_text_field( $text( $r, 'family_email' ) ),
				'family_address'      => sanitize_textarea_field( $text( $r, 'family_address' ) ),
				'family_send_card'    => $flag( $r, 'family_send_card' ),
				'email_family'        => $flag( $r, 'email_family' ),
				'email_donor'         => $flag( $r, 'email_donor' ),
			];

			// A row added and left empty is not a memorial.
			if ( '' === $row['honoree_name'] && '' === $row['amount'] && 0 === $row['donor_id']
				&& '' === $row['donor_name'] && '' === $row['donor_email'] ) {
				continue;
			}
			if ( '' === $row['date'] ) {
				$row['date'] = wp_date( 'Y-m-d' );
			}
			$rows[] = $row;
		}
		return $rows;
	}

	/**
	 * What is wrong with a row, by field.
	 *
	 * @param array $row A parsed row.
	 * @return array<string, string> Field => message. Empty when the row is fine.
	 */
	public static function validate( array $row ): array {
		$errors = [];

		// The donor: one chosen in the search, or a new one.
		if ( $row['donor_id'] > 0 ) {
			$donor = get_post( $row['donor_id'] );
			if ( ! $donor || 'skdon_donor' !== $donor->post_type || 'publish' !== $donor->post_status ) {
				$errors['donor_id'] = __( 'That donor no longer exists. Choose another, or enter a new donor.', 'shelterkit-donations' );
			}
		} else {
			if ( '' === $row['donor_name'] ) {
				$errors['donor_name'] = __( 'Choose a donor in the search, or enter a new donor\'s name.', 'shelterkit-donations' );
			}
			if ( '' !== $row['donor_email'] && ! is_email( $row['donor_email'] ) ) {
				$errors['donor_email'] = __( 'Enter a valid email address, or leave it empty.', 'shelterkit-donations' );
			}
			// With no email to tell two people apart, a name already on file
			// is probably the same person: ask rather than make a second record.
			if ( '' !== $row['donor_name'] && '' === $row['donor_email'] && ! $row['new_donor_confirmed']
				&& Donor_Lookup::find_by_name( $row['donor_name'] ) ) {
				$errors['donor_duplicate'] = sprintf(
					/* translators: %s: donor's name. */
					__( 'A donor named “%s” is already on file. Choose them in the search, or tick “This is a different person”.', 'shelterkit-donations' ),
					$row['donor_name']
				);
			}
		}

		if ( '' === $row['honoree_name'] ) {
			$errors['honoree_name'] = __( 'Enter the name of the person or pet the gift remembers.', 'shelterkit-donations' );
		}
		if ( ! array_key_exists( $row['memorial_type'], self::memorial_types() ) ) {
			$errors['memorial_type'] = __( 'Choose person or pet.', 'shelterkit-donations' );
		}
		if ( '' !== $row['pet_species'] && 'pet' === $row['memorial_type'] && ! array_key_exists( $row['pet_species'], self::pet_species() ) ) {
			$errors['pet_species'] = __( 'Choose a species from the list.', 'shelterkit-donations' );
		}

		$amount = self::parse_amount( $row['amount'] );
		if ( null === $amount || $amount <= 0 ) {
			$errors['amount'] = __( 'Enter an amount greater than zero.', 'shelterkit-donations' );
		}

		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $row['date'], wp_timezone() );
		if ( ! $date || $date->format( 'Y-m-d' ) !== $row['date'] ) {
			$errors['date'] = __( 'Enter the date the gift was received.', 'shelterkit-donations' );
		} elseif ( $row['date'] > wp_date( 'Y-m-d' ) ) {
			$errors['date'] = __( 'The date can\'t be in the future.', 'shelterkit-donations' );
		}

		if ( $row['campaign_id'] > 0 && ! term_exists( $row['campaign_id'], 'skdon_campaign' ) ) {
			$errors['campaign_id'] = __( 'That campaign no longer exists.', 'shelterkit-donations' );
		}

		if ( $row['notify_family'] && '' !== $row['family_email'] && ! is_email( $row['family_email'] ) ) {
			$errors['family_email'] = __( 'Enter a valid email address, or leave it empty.', 'shelterkit-donations' );
		} elseif ( $row['email_family'] && ! ( $row['notify_family'] && is_email( $row['family_email'] ) ) ) {
			$errors['family_email'] = __( 'Enter the family\'s email address to email them, or untick “Email the family now”.', 'shelterkit-donations' );
		}

		if ( $row['email_donor'] && ! isset( $errors['donor_id'] ) && ! is_email( self::donor_email( $row ) ) ) {
			$errors['email_donor'] = __( 'This donor has no email address. Add one, or untick “Email the donor a confirmation”.', 'shelterkit-donations' );
		}

		return $errors;
	}

	/**
	 * Create each row's memorial, then send the emails that were asked for.
	 *
	 * Created as internal processing, as imports are, so the memorial emails
	 * don't go out by themselves; each one asked for is sent afterwards.
	 *
	 * @param array<int, array> $rows Valid rows.
	 * @return array<int, array> One result per row.
	 */
	private static function create_all( array $rows ): array {
		$results = [];
		$was     = Helpers\is_internal_processing();
		Helpers\set_internal_processing( true );
		try {
			foreach ( $rows as $row ) {
				$results[] = self::create( $row );
			}
		} finally {
			Helpers\set_internal_processing( $was );
		}

		foreach ( $results as $n => $result ) {
			if ( empty( $result['memorial_id'] ) ) {
				continue;
			}
			if ( $rows[ $n ]['email_donor'] ) {
				$sent                         = Helpers\send_memorial_confirmation( $result['memorial_id'] );
				$results[ $n ]['donor_email'] = true === $sent ? true : $sent->get_error_message();
			}
			if ( $rows[ $n ]['email_family'] ) {
				$sent                          = Family_Notification::send( $result['memorial_id'] );
				$results[ $n ]['family_email'] = true === $sent ? true : $sent->get_error_message();
			}
		}
		return $results;
	}

	/**
	 * Create one memorial.
	 *
	 * @param array $row A valid row.
	 * @return array{honoree_name: string, dedication_type: string, amount: float, memorial_id?: int, donor_id?: int, donor_name?: string, donor_created?: bool, error?: string}
	 */
	private static function create( array $row ): array {
		$amount = (float) self::parse_amount( $row['amount'] );
		$result = [
			'honoree_name'    => $row['honoree_name'],
			'dedication_type' => $row['dedication_type'],
			'amount'          => $amount,
		];

		if ( $row['donor_id'] > 0 ) {
			$donor = [ 'id' => $row['donor_id'], 'created' => false ];
		} else {
			$donor = Donor_Lookup::find_or_create( [
				'email'         => $row['donor_email'],
				'display_name'  => $row['donor_name'],
				'force_new'     => $row['new_donor_confirmed'],
				'import_source' => 'quick_add',
			] );
		}
		if ( is_wp_error( $donor ) ) {
			return $result + [ 'error' => $donor->get_error_message() ];
		}

		$input = [
			'donor_id'        => (int) $donor['id'],
			'honoree_name'    => $row['honoree_name'],
			'memorial_type'   => $row['memorial_type'],
			'dedication_type' => $row['dedication_type'],
			'amount'          => $amount,
			'is_anonymous'    => $row['is_anonymous'],
			// Today's gift at the time it was entered; an earlier one at noon,
			// so no timezone moves it to the day before.
			'donation_date'   => $row['date'] === wp_date( 'Y-m-d' ) ? wp_date( 'Y-m-d H:i:s' ) : $row['date'] . ' 12:00:00',
		];
		if ( '' !== $row['donor_name'] && 0 === $row['donor_id'] ) {
			$input['donor_name'] = $row['donor_name'];
		}
		if ( 'pet' === $row['memorial_type'] && '' !== $row['pet_species'] ) {
			$input['pet_species'] = $row['pet_species'];
		}
		if ( '' !== $row['tribute_message'] ) {
			$input['tribute_message'] = $row['tribute_message'];
		}
		if ( $row['campaign_id'] > 0 ) {
			$input['campaign_id'] = $row['campaign_id'];
		}
		if ( $row['notify_family'] ) {
			// Empty values left out: the schema checks the email's format.
			$input['notify_family'] = array_filter( [
				'enabled'   => true,
				'name'      => $row['family_name'],
				'email'     => $row['family_email'],
				'address'   => $row['family_address'],
				'send_card' => $row['family_send_card'],
			], static fn( $value ) => '' !== $value );
		}

		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'shelter-memorials/create' ) : null;
		$created = $ability ? $ability->execute( $input ) : new \WP_Error( 'no_ability', __( 'The memorial ability could not be loaded.', 'shelterkit-donations' ) );
		if ( is_wp_error( $created ) ) {
			return $result + [ 'error' => $created->get_error_message() ];
		}

		return $result + [
			'memorial_id'   => (int) $created['memorial_id'],
			'donor_id'      => (int) $donor['id'],
			'donor_name'    => (string) ( get_post_meta( (int) $donor['id'], '_skdon_display_name', true ) ?: get_the_title( (int) $donor['id'] ) ),
			'donor_created' => (bool) $donor['created'],
		];
	}

	/**
	 * An amount as typed: "25", "25.00", "$1,250" or, in a store that writes
	 * decimals with a comma, "25,00".
	 *
	 * @param string $raw As typed.
	 * @return float|null
	 */
	public static function parse_amount( string $raw ): ?float {
		$clean = preg_replace( '/[^\d.,\-]/u', '', $raw );
		if ( null === $clean || '' === $clean ) {
			return null;
		}
		$clean = function_exists( 'wc_format_decimal' )
			? (string) wc_format_decimal( $clean )
			: str_replace( ',', '', $clean );
		return is_numeric( $clean ) ? round( (float) $clean, 2 ) : null;
	}

	/**
	 * The email address the donor confirmation would go to.
	 *
	 * @param array $row A parsed row.
	 */
	private static function donor_email( array $row ): string {
		if ( $row['donor_id'] > 0 ) {
			return (string) get_post_meta( $row['donor_id'], '_skdon_email', true );
		}
		if ( '' !== $row['donor_email'] ) {
			return $row['donor_email'];
		}
		return '';
	}

	// ─── Donor search ──────────────────────────────────────────────

	/**
	 * The donor search's AJAX endpoint.
	 */
	public static function ajax_donor_search(): void {
		check_ajax_referer( self::SEARCH_NONCE, 'nonce' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( null, 403 );
		}
		$term = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['term'] ) ) : '';
		wp_send_json_success( self::search_donors( $term ) );
	}

	/**
	 * Donors whose name or email contains the term, with their email so two
	 * people with the same name can be told apart.
	 *
	 * @param string $term What was typed.
	 * @return array<int, array{id: int, text: string}>
	 */
	public static function search_donors( string $term ): array {
		if ( mb_strlen( $term ) < 2 ) {
			return [];
		}
		$base = [
			'post_type'      => 'skdon_donor',
			'post_status'    => 'publish',
			'posts_per_page' => 20,
			'fields'         => 'ids',
			'orderby'        => 'title',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		];
		$by_name  = get_posts( $base + [ 's' => $term, 'search_columns' => [ 'post_title' ] ] );
		$by_email = get_posts( $base + [
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- a typed search over donors, at most 20 rows.
			'meta_query' => [ [ 'key' => '_skdon_email', 'value' => $term, 'compare' => 'LIKE' ] ],
		] );

		$ids = array_slice( array_unique( array_merge( $by_name, $by_email ) ), 0, 20 );
		return array_map( static fn( int $id ): array => [ 'id' => $id, 'text' => self::donor_label( $id ) ], $ids );
	}

	/**
	 * "Pat Alpha — pat@example.org", or "Pat Alpha (no email)".
	 *
	 * @param int $donor_id Donor post ID.
	 */
	private static function donor_label( int $donor_id ): string {
		$name  = (string) ( get_post_meta( $donor_id, '_skdon_display_name', true ) ?: get_the_title( $donor_id ) );
		$email = (string) get_post_meta( $donor_id, '_skdon_email', true );
		return '' !== $email
			/* translators: 1: donor's name; 2: donor's email address. */
			? sprintf( __( '%1$s — %2$s', 'shelterkit-donations' ), $name, $email )
			/* translators: %s: donor's name. */
			: sprintf( __( '%s (no email)', 'shelterkit-donations' ), $name );
	}

	// ─── Rendering ─────────────────────────────────────────────────

	/**
	 * The screen: what was just added, then a form with one row or more.
	 */
	public static function render_page(): void {
		$state  = self::$state;
		$rows   = $state['rows'] ?? [];
		$errors = $state['errors'] ?? [];
		if ( ! $rows ) {
			$rows = [ self::blank_row() ];
		}
		?>
		<div class="wrap sd-quick-add">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Quick Add Memorials', 'shelterkit-donations' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . self::POST_TYPE ) ); ?>" class="page-title-action"><?php esc_html_e( 'All Memorials', 'shelterkit-donations' ); ?></a>
			<hr class="wp-header-end">

			<?php self::render_results(); ?>

			<?php if ( ! empty( $state['message'] ) ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $state['message'] ); ?></p></div>
			<?php endif; ?>

			<p class="sd-qa-intro">
				<?php esc_html_e( 'For memorial gifts received by mail or in person. Each one counts toward the donor\'s lifetime giving and appears on the memorial wall, as a gift made online does. No one is emailed unless you tick it.', 'shelterkit-donations' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( self::url() ); ?>" novalidate>
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="skdon_quick_add" value="1">
				<div id="sd-qa-rows">
					<?php
					foreach ( array_values( $rows ) as $i => $row ) {
						self::render_row( (string) $i, $row, $errors[ $i ] ?? [] );
					}
					?>
				</div>
				<p><button type="button" class="button sd-qa-add-row"><?php esc_html_e( '+ Add another memorial', 'shelterkit-donations' ); ?></button></p>
				<?php submit_button( __( 'Add memorials', 'shelterkit-donations' ) ); ?>
			</form>

			<template id="sd-qa-row-template"><?php self::render_row( '__INDEX__', self::blank_row(), [] ); ?></template>
		</div>
		<?php
	}

	/**
	 * A row with nothing entered.
	 *
	 * @return array
	 */
	private static function blank_row(): array {
		return [
			'donor_id'            => 0,
			'donor_name'          => '',
			'donor_email'         => '',
			'new_donor_confirmed' => false,
			'is_anonymous'        => false,
			'dedication_type'     => 'memory',
			'honoree_name'        => '',
			'memorial_type'       => 'person',
			'pet_species'         => '',
			'amount'              => '',
			'date'                => wp_date( 'Y-m-d' ),
			'campaign_id'         => 0,
			'tribute_message'     => '',
			'notify_family'       => false,
			'family_name'         => '',
			'family_email'        => '',
			'family_address'      => '',
			'family_send_card'    => false,
			'email_family'        => false,
			'email_donor'         => false,
		];
	}

	/**
	 * One memorial's fields.
	 *
	 * @param string $i      Row index, or __INDEX__ in the template.
	 * @param array  $row    Its values.
	 * @param array  $errors Its errors, by field.
	 */
	private static function render_row( string $i, array $row, array $errors ): void {
		$name  = static fn( string $field ): string => "qa[{$i}][{$field}]";
		$id    = static fn( string $field ): string => "sd-qa-{$i}-{$field}";
		$error = static function ( string $field ) use ( $errors, $id ): void {
			if ( isset( $errors[ $field ] ) ) {
				printf( '<span class="sd-qa-error" id="%s">%s</span>', esc_attr( $id( $field ) . '-error' ), esc_html( $errors[ $field ] ) );
			}
		};
		$invalid = static function ( string ...$fields ) use ( $errors, $id ): void {
			foreach ( $fields as $field ) {
				if ( isset( $errors[ $field ] ) ) {
					printf( ' aria-invalid="true" aria-describedby="%s"', esc_attr( $id( $field ) . '-error' ) );
					return;
				}
			}
		};
		$campaigns = self::campaigns();
		?>
		<fieldset class="sd-qa-row<?php echo $errors ? ' sd-qa-row-invalid' : ''; ?>">
			<legend>
				<?php
				/* translators: %s: the memorial's number on this screen. */
				printf( esc_html__( 'Memorial %s', 'shelterkit-donations' ), '<span class="sd-qa-number">' . esc_html( is_numeric( $i ) ? (string) ( (int) $i + 1 ) : '' ) . '</span>' );
				?>
			</legend>
			<button type="button" class="button-link button-link-delete sd-qa-remove"><?php esc_html_e( 'Remove', 'shelterkit-donations' ); ?></button>

			<div class="sd-qa-section">
				<h3><?php esc_html_e( 'Donor', 'shelterkit-donations' ); ?></h3>
				<div class="sd-qa-grid">
					<label class="sd-qa-wide"><?php esc_html_e( 'Donor on file', 'shelterkit-donations' ); ?>
						<select class="sd-qa-donor" name="<?php echo esc_attr( $name( 'donor_id' ) ); ?>" data-placeholder="<?php esc_attr_e( 'Search by name or email', 'shelterkit-donations' ); ?>"<?php $invalid( 'donor_id' ); ?>>
							<option value=""></option>
							<?php if ( $row['donor_id'] > 0 ) : ?>
								<option value="<?php echo esc_attr( (string) $row['donor_id'] ); ?>" selected><?php echo esc_html( self::donor_label( $row['donor_id'] ) ); ?></option>
							<?php endif; ?>
						</select>
						<?php $error( 'donor_id' ); ?>
					</label>
					<label class="sd-qa-new-donor"><?php esc_html_e( 'Or a new donor: name', 'shelterkit-donations' ); ?>
						<input type="text" name="<?php echo esc_attr( $name( 'donor_name' ) ); ?>" value="<?php echo esc_attr( $row['donor_name'] ); ?>" autocomplete="off"<?php $invalid( 'donor_name', 'donor_duplicate' ); ?>>
						<?php $error( 'donor_name' ); ?>
						<?php $error( 'donor_duplicate' ); ?>
					</label>
					<label class="sd-qa-new-donor"><?php esc_html_e( 'Email (optional)', 'shelterkit-donations' ); ?>
						<input type="email" name="<?php echo esc_attr( $name( 'donor_email' ) ); ?>" value="<?php echo esc_attr( $row['donor_email'] ); ?>" autocomplete="off"<?php $invalid( 'donor_email' ); ?>>
						<?php $error( 'donor_email' ); ?>
					</label>
				</div>
				<?php if ( isset( $errors['donor_duplicate'] ) || $row['new_donor_confirmed'] ) : ?>
					<label class="sd-qa-check"><input type="checkbox" name="<?php echo esc_attr( $name( 'new_donor_confirmed' ) ); ?>" value="1" <?php checked( $row['new_donor_confirmed'] ); ?>> <?php esc_html_e( 'This is a different person: add a new donor', 'shelterkit-donations' ); ?></label>
				<?php endif; ?>
				<label class="sd-qa-check"><input type="checkbox" name="<?php echo esc_attr( $name( 'is_anonymous' ) ); ?>" value="1" <?php checked( $row['is_anonymous'] ); ?>> <?php esc_html_e( 'Keep the donor\'s name off the memorial', 'shelterkit-donations' ); ?></label>
			</div>

			<div class="sd-qa-section">
				<h3><?php esc_html_e( 'Memorial', 'shelterkit-donations' ); ?></h3>
				<div class="sd-qa-grid">
					<label><?php esc_html_e( 'Gift', 'shelterkit-donations' ); ?>
						<select name="<?php echo esc_attr( $name( 'dedication_type' ) ); ?>">
							<option value="memory" <?php selected( $row['dedication_type'], 'memory' ); ?>><?php esc_html_e( 'In memory of', 'shelterkit-donations' ); ?></option>
							<option value="honor" <?php selected( $row['dedication_type'], 'honor' ); ?>><?php esc_html_e( 'In honor of', 'shelterkit-donations' ); ?></option>
						</select>
					</label>
					<label><?php esc_html_e( 'Name', 'shelterkit-donations' ); ?>
						<input type="text" name="<?php echo esc_attr( $name( 'honoree_name' ) ); ?>" value="<?php echo esc_attr( $row['honoree_name'] ); ?>" maxlength="100"<?php $invalid( 'honoree_name' ); ?>>
						<?php $error( 'honoree_name' ); ?>
					</label>
					<fieldset class="sd-qa-type">
						<legend><?php esc_html_e( 'Who', 'shelterkit-donations' ); ?></legend>
						<?php foreach ( self::memorial_types() as $value => $label ) : ?>
							<label><input type="radio" class="sd-qa-type-input" name="<?php echo esc_attr( $name( 'memorial_type' ) ); ?>" value="<?php echo esc_attr( (string) $value ); ?>" <?php checked( $row['memorial_type'], (string) $value ); ?>> <?php echo esc_html( (string) $label ); ?></label>
						<?php endforeach; ?>
						<?php $error( 'memorial_type' ); ?>
					</fieldset>
					<label class="sd-qa-species"><?php esc_html_e( 'Species', 'shelterkit-donations' ); ?>
						<select name="<?php echo esc_attr( $name( 'pet_species' ) ); ?>"<?php $invalid( 'pet_species' ); ?>>
							<option value=""><?php esc_html_e( '—', 'shelterkit-donations' ); ?></option>
							<?php foreach ( self::pet_species() as $value => $label ) : ?>
								<option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( $row['pet_species'], (string) $value ); ?>><?php echo esc_html( (string) $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<?php $error( 'pet_species' ); ?>
					</label>
				</div>
				<label class="sd-qa-block"><?php esc_html_e( 'Tribute message (optional)', 'shelterkit-donations' ); ?>
					<textarea name="<?php echo esc_attr( $name( 'tribute_message' ) ); ?>" rows="2"><?php echo esc_textarea( $row['tribute_message'] ); ?></textarea>
				</label>
			</div>

			<div class="sd-qa-section">
				<h3><?php esc_html_e( 'Gift received', 'shelterkit-donations' ); ?></h3>
				<div class="sd-qa-grid">
					<label><?php esc_html_e( 'Amount', 'shelterkit-donations' ); ?>
						<input type="text" inputmode="decimal" name="<?php echo esc_attr( $name( 'amount' ) ); ?>" value="<?php echo esc_attr( $row['amount'] ); ?>"<?php $invalid( 'amount' ); ?>>
						<?php $error( 'amount' ); ?>
					</label>
					<label><?php esc_html_e( 'Date received', 'shelterkit-donations' ); ?>
						<input type="date" name="<?php echo esc_attr( $name( 'date' ) ); ?>" value="<?php echo esc_attr( $row['date'] ); ?>" max="<?php echo esc_attr( wp_date( 'Y-m-d' ) ); ?>"<?php $invalid( 'date' ); ?>>
						<?php $error( 'date' ); ?>
					</label>
					<?php if ( $campaigns ) : ?>
						<label><?php esc_html_e( 'Campaign (optional)', 'shelterkit-donations' ); ?>
							<select name="<?php echo esc_attr( $name( 'campaign_id' ) ); ?>"<?php $invalid( 'campaign_id' ); ?>>
								<option value="0"><?php esc_html_e( 'None', 'shelterkit-donations' ); ?></option>
								<?php foreach ( $campaigns as $term_id => $label ) : ?>
									<option value="<?php echo esc_attr( (string) $term_id ); ?>" <?php selected( $row['campaign_id'], $term_id ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<?php $error( 'campaign_id' ); ?>
						</label>
					<?php endif; ?>
				</div>
			</div>

			<div class="sd-qa-section">
				<label class="sd-qa-check"><input type="checkbox" class="sd-qa-family-toggle" name="<?php echo esc_attr( $name( 'notify_family' ) ); ?>" value="1" <?php checked( $row['notify_family'] ); ?>> <?php esc_html_e( 'The donor asked for the family to be told', 'shelterkit-donations' ); ?></label>
				<div class="sd-qa-family">
					<div class="sd-qa-grid">
						<label><?php esc_html_e( 'Family member\'s name', 'shelterkit-donations' ); ?>
							<input type="text" name="<?php echo esc_attr( $name( 'family_name' ) ); ?>" value="<?php echo esc_attr( $row['family_name'] ); ?>">
						</label>
						<label><?php esc_html_e( 'Their email', 'shelterkit-donations' ); ?>
							<input type="email" name="<?php echo esc_attr( $name( 'family_email' ) ); ?>" value="<?php echo esc_attr( $row['family_email'] ); ?>"<?php $invalid( 'family_email' ); ?>>
							<?php $error( 'family_email' ); ?>
						</label>
						<label class="sd-qa-wide"><?php esc_html_e( 'Their mailing address', 'shelterkit-donations' ); ?>
							<textarea name="<?php echo esc_attr( $name( 'family_address' ) ); ?>" rows="2"><?php echo esc_textarea( $row['family_address'] ); ?></textarea>
						</label>
					</div>
					<label class="sd-qa-check"><input type="checkbox" name="<?php echo esc_attr( $name( 'family_send_card' ) ); ?>" value="1" <?php checked( $row['family_send_card'] ); ?>> <?php esc_html_e( 'Mail them a card', 'shelterkit-donations' ); ?></label>
					<label class="sd-qa-check"><input type="checkbox" name="<?php echo esc_attr( $name( 'email_family' ) ); ?>" value="1" <?php checked( $row['email_family'] ); ?>> <?php esc_html_e( 'Email the family now', 'shelterkit-donations' ); ?></label>
				</div>
			</div>

			<div class="sd-qa-section">
				<label class="sd-qa-check"><input type="checkbox" name="<?php echo esc_attr( $name( 'email_donor' ) ); ?>"<?php $invalid( 'email_donor' ); ?> value="1" <?php checked( $row['email_donor'] ); ?>> <?php esc_html_e( 'Email the donor a confirmation', 'shelterkit-donations' ); ?></label>
				<?php $error( 'email_donor' ); ?>
			</div>
		</fieldset>
		<?php
	}

	/**
	 * What the last submission added, once, to the person who added it.
	 */
	private static function render_results(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a key to this user's own result, shown once.
		$token = isset( $_GET['added'] ) ? sanitize_key( wp_unslash( (string) $_GET['added'] ) ) : '';
		if ( '' === $token ) {
			return;
		}
		$data = get_transient( 'skdon_quick_add_' . $token );
		if ( ! is_array( $data ) || (int) ( $data['user'] ?? 0 ) !== get_current_user_id() ) {
			return;
		}
		delete_transient( 'skdon_quick_add_' . $token );

		$results = (array) ( $data['results'] ?? [] );
		$added   = array_filter( $results, static fn( $r ) => ! empty( $r['memorial_id'] ) );
		$failed  = array_filter( $results, static fn( $r ) => empty( $r['memorial_id'] ) );
		?>
		<?php if ( $added ) : ?>
			<div class="notice notice-success sd-qa-results">
				<p><strong>
					<?php
					/* translators: %d: number of memorials added. */
					echo esc_html( sprintf( _n( 'Added %d memorial.', 'Added %d memorials.', count( $added ), 'shelterkit-donations' ), count( $added ) ) );
					?>
				</strong></p>
				<ul>
					<?php foreach ( $added as $r ) : ?>
						<li><?php echo wp_kses( self::describe_result( $r ), [ 'a' => [ 'href' => [] ] ] ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>
		<?php if ( $failed ) : ?>
			<div class="notice notice-error">
				<ul>
					<?php foreach ( $failed as $r ) : ?>
						<li>
							<?php
							echo esc_html( sprintf(
								/* translators: 1: honoree's name; 2: why it failed. */
								__( 'The memorial for %1$s wasn\'t added: %2$s', 'shelterkit-donations' ),
								$r['honoree_name'],
								$r['error'] ?? ''
							) );
							?>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * "In memory of Rex, $25.00, from Pat Alpha (new donor). Confirmation
	 * emailed. Edit", with the reason when an email wasn't sent.
	 *
	 * @param array $r A result from create_all().
	 * @return string HTML.
	 */
	private static function describe_result( array $r ): string {
		$parts = [
			sprintf(
				/* translators: 1: "In memory of" or "In honor of"; 2: honoree's name; 3: amount; 4: donor's name. */
				__( '%1$s %2$s, %3$s, from %4$s', 'shelterkit-donations' ),
				'honor' === $r['dedication_type'] ? __( 'In honor of', 'shelterkit-donations' ) : __( 'In memory of', 'shelterkit-donations' ),
				$r['honoree_name'],
				Helpers\format_currency( (float) $r['amount'] ),
				$r['donor_name'] ?? ''
			) . ( ! empty( $r['donor_created'] ) ? ' ' . __( '(new donor)', 'shelterkit-donations' ) : '' ) . '.',
		];
		if ( array_key_exists( 'donor_email', $r ) ) {
			$parts[] = true === $r['donor_email']
				? __( 'Confirmation emailed.', 'shelterkit-donations' )
				/* translators: %s: why it wasn't sent. */
				: sprintf( __( 'Confirmation not sent: %s', 'shelterkit-donations' ), (string) $r['donor_email'] );
		}
		if ( array_key_exists( 'family_email', $r ) ) {
			$parts[] = true === $r['family_email']
				? __( 'Family emailed.', 'shelterkit-donations' )
				/* translators: %s: why it wasn't sent. */
				: sprintf( __( 'Family not emailed: %s', 'shelterkit-donations' ), (string) $r['family_email'] );
		}

		$text = esc_html( implode( ' ', $parts ) );
		$edit = get_edit_post_link( (int) $r['memorial_id'], 'raw' );
		return $edit ? $text . ' <a href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit', 'shelterkit-donations' ) . '</a>' : $text;
	}

	// ─── Choices ───────────────────────────────────────────────────

	/**
	 * Person or pet, as the settings name them.
	 *
	 * @return array<string, string>
	 */
	private static function memorial_types(): array {
		$types = (array) Config::get_item( 'settings', 'memorial_types', [] );
		return $types ?: [ 'person' => __( 'Person', 'shelterkit-donations' ), 'pet' => __( 'Pet', 'shelterkit-donations' ) ];
	}

	/**
	 * The pet species the settings list.
	 *
	 * @return array<string, string>
	 */
	private static function pet_species(): array {
		return (array) Config::get_item( 'settings', 'pet_species', [] );
	}

	/**
	 * The campaigns a gift can count toward.
	 *
	 * @return array<int, string> Campaign term ID => name.
	 */
	private static function campaigns(): array {
		$terms = get_terms( [ 'taxonomy' => 'skdon_campaign', 'hide_empty' => false ] );
		if ( is_wp_error( $terms ) ) {
			return [];
		}
		$out = [];
		foreach ( $terms as $term ) {
			$out[ (int) $term->term_id ] = $term->name;
		}
		return $out;
	}

	private static function css(): string {
		return '
.sd-quick-add .sd-qa-intro { max-width: 760px; }
.sd-qa-row { background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 8px 16px 12px; margin: 0 0 16px; max-width: 980px; position: relative; }
.sd-qa-row > legend { font-size: 14px; font-weight: 600; padding: 0 6px; }
.sd-qa-row-invalid { border-color: #d63638; }
.sd-qa-remove { position: absolute; top: 8px; right: 16px; }
.sd-qa-section { border-top: 1px solid #f0f0f1; padding: 10px 0 4px; }
.sd-qa-section:first-of-type { border-top: 0; }
.sd-qa-section h3 { font-size: 13px; margin: 0 0 8px; }
.sd-qa-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px 16px; align-items: start; }
.sd-qa-grid label, .sd-qa-block { display: flex; flex-direction: column; gap: 4px; font-weight: 500; }
.sd-qa-grid .sd-qa-wide { grid-column: 1 / -1; }
.sd-qa-grid input[type=text], .sd-qa-grid input[type=email], .sd-qa-grid input[type=date], .sd-qa-grid select, .sd-qa-grid textarea, .sd-qa-block textarea { width: 100%; max-width: none; }
.sd-qa-block { margin-top: 10px; }
.sd-qa-type { border: 0; margin: 0; padding: 0; display: flex; flex-wrap: wrap; gap: 4px 14px; align-items: center; }
.sd-qa-type legend { font-weight: 500; padding: 0; margin-bottom: 4px; width: 100%; }
.sd-qa-type label { display: inline-flex; flex-direction: row; gap: 4px; font-weight: 400; }
.sd-qa-check { display: block; margin: 6px 0; }
.sd-qa-family { margin: 4px 0 0 24px; }
.sd-qa-error { color: #d63638; font-weight: 400; font-size: 12px; }
.sd-qa-hidden { display: none !important; }
.sd-qa-results ul { list-style: disc; margin-left: 20px; }
';
	}
}
