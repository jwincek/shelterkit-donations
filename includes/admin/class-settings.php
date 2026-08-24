<?php
/**
 * Admin Settings - Config-driven settings page with tabs.
 *
 * @package Starter_Shelter
 * @subpackage Admin
 * @since 1.0.0
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Admin;

use Starter_Shelter\Core\Config;
use Starter_Shelter\Core\Activator;

/**
 * Handles plugin settings using the WordPress Settings API.
 *
 * @since 1.0.0
 */
class Settings {

    private const OPTION_GROUP = 'starter_shelter_settings';
    private const OPTION_NAME = 'starter_shelter_options';
    private const PAGE_SLUG = 'shelterkit-donations-settings';

    /**
     * Settings tabs, keyed by slug.
     *
     * @var array<string, string>
     */
    private static array $tabs = [
        'general'  => 'General',
        'data'     => 'Data',
        'products' => 'Products',
        'emails'   => 'Emails',
    ];

    public static function init(): void {
        add_action( 'admin_menu', [ self::class, 'add_settings_page' ] );
        add_action( 'admin_init', [ self::class, 'register_settings' ] );
        add_action( 'admin_init', [ self::class, 'handle_product_actions' ] );
    }

    public static function add_settings_page(): void {
        add_submenu_page(
            Menu::MENU_SLUG,
            __( 'Shelter Donations Settings', 'shelterkit-donations' ),
            __( 'Settings', 'shelterkit-donations' ),
            'manage_options',
            self::PAGE_SLUG,
            [ self::class, 'render_settings_page' ]
        );
    }

    public static function handle_product_actions(): void {
        if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== $_GET['page'] ) {
            return;
        }

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        if ( isset( $_GET['action'] ) && 'create_products' === $_GET['action'] ) {
            check_admin_referer( 'sd_create_products' );

            Activator::reset_product_flags();
            Activator::maybe_create_products();

            wp_safe_redirect( add_query_arg( [
                'page'    => self::PAGE_SLUG,
                'tab'     => 'products',
                'message' => 'products_created',
            ], admin_url( 'admin.php' ) ) );
            exit;
        }

        // Handle data tab saves.
        if ( isset( $_POST['sd_save_data_config'] ) ) {
            check_admin_referer( 'sd_data_config' );
            self::save_data_tab();

            wp_safe_redirect( add_query_arg( [
                'page'    => self::PAGE_SLUG,
                'tab'     => 'data',
                'message' => 'data_saved',
            ], admin_url( 'admin.php' ) ) );
            exit;
        }

        if ( isset( $_POST['sd_save_product_mappings'] ) ) {
            check_admin_referer( 'sd_product_mappings' );

            $product_options = [
                'sd_donation_product_id',
                'sd_membership_product_id',
                'sd_business_membership_product_id',
                'sd_memorial_product_id',
            ];

            foreach ( $product_options as $option ) {
                if ( isset( $_POST[ $option ] ) ) {
                    update_option( $option, absint( $_POST[ $option ] ) );
                }
            }

            wp_safe_redirect( add_query_arg( [
                'page'    => self::PAGE_SLUG,
                'tab'     => 'products',
                'message' => 'products_saved',
            ], admin_url( 'admin.php' ) ) );
            exit;
        }
    }

    private static function get_current_tab(): string {
        $tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general';
        return array_key_exists( $tab, self::$tabs ) ? $tab : 'general';
    }

    public static function register_settings(): void {
        register_setting( self::OPTION_GROUP, self::OPTION_NAME, [
            'type'              => 'array',
            'sanitize_callback' => [ self::class, 'sanitize_settings' ],
            'default'           => self::get_defaults(),
        ] );

        self::register_sections();
        self::register_fields();
    }

    private static function register_sections(): void {
        add_settings_section( 'sd_general', __( 'General Settings', 'shelterkit-donations' ), function () {
            echo '<p>' . esc_html__( 'Configure general plugin settings.', 'shelterkit-donations' ) . '</p>';
        }, self::PAGE_SLUG . '_general' );

        add_settings_section( 'sd_pages', __( 'Pages', 'shelterkit-donations' ), function () {
            echo '<p>' . esc_html__( 'Map donate and join CTAs to specific pages on your site. When unset, those buttons are hidden rather than emit broken /donate/ links.', 'shelterkit-donations' ) . '</p>';
        }, self::PAGE_SLUG . '_general' );

        add_settings_section( 'sd_features', __( 'Features', 'shelterkit-donations' ), function () {
            echo '<p>' . esc_html__( 'Enable or disable plugin features.', 'shelterkit-donations' ) . '</p>';
        }, self::PAGE_SLUG . '_general' );

        add_settings_section( 'sd_emails', __( 'Email Settings', 'shelterkit-donations' ), function () {
            echo '<p>' . esc_html__( 'Configure email notifications.', 'shelterkit-donations' ) . '</p>';
        }, self::PAGE_SLUG . '_emails' );

        add_settings_section( 'sd_data', __( 'Data Management', 'shelterkit-donations' ), function () {
            echo '<p>' . esc_html__( 'Control what happens to your data when the plugin is uninstalled.', 'shelterkit-donations' ) . '</p>';
        }, self::PAGE_SLUG . '_general' );
    }

    private static function register_fields(): void {
        // General tab fields
        self::add_field( 'fiscal_year_start_month', __( 'Fiscal Year Start Month', 'shelterkit-donations' ), 'sd_general', 'select', [
            'options' => array_combine( range( 1, 12 ), [
                'January', 'February', 'March', 'April', 'May', 'June',
                'July', 'August', 'September', 'October', 'November', 'December',
            ] ),
            'default' => 7,
        ], 'general' );

        self::add_field( 'renewal_reminder_days', __( 'Membership Renewal Reminder (days)', 'shelterkit-donations' ), 'sd_general', 'number', [
            'default' => 30, 'min' => 7, 'max' => 90,
        ], 'general' );





        // Page mappings.
        self::add_field( 'donation_page', __( 'Donation Page', 'shelterkit-donations' ), 'sd_pages', 'page', [
            'description' => __( 'Where "Donate Now" buttons (including campaign-card on donation drives) should link. Campaign-card appends ?campaign={id}.', 'shelterkit-donations' ),
        ], 'general' );

        self::add_field( 'membership_page', __( 'Membership Page', 'shelterkit-donations' ), 'sd_pages', 'page', [
            'description' => __( 'Where "Join Now" buttons (campaign-card on membership drives) should link. Campaign-card appends ?campaign={id}.', 'shelterkit-donations' ),
        ], 'general' );

        // Feature toggles
        foreach ( [
            'feature_anonymous_donations'  => 'Allow Anonymous Donations',
            'feature_dedications'          => 'Enable Donation Dedications',
            'feature_family_notifications' => 'Enable Memorial Family Notifications',
            'feature_renewal_reminders'    => 'Send Membership Renewal Reminders',
            'feature_annual_statements'    => 'Send Annual Giving Statements',
        ] as $id => $label ) {
            self::add_field( $id, __( $label, 'shelterkit-donations' ), 'sd_features', 'checkbox', [ 'default' => true ], 'general' ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- feature toggle label sourced from the settings config map, not a literal.
        }

        // Email tab fields
        self::add_field( 'email_from_name', __( 'Email From Name', 'shelterkit-donations' ), 'sd_emails', 'text', [
            'default' => get_bloginfo( 'name' ),
        ], 'emails' );

        self::add_field( 'email_from_address', __( 'Email From Address', 'shelterkit-donations' ), 'sd_emails', 'email', [
            'default' => get_option( 'admin_email' ),
        ], 'emails' );

        self::add_field( 'logo_moderation_email', __( 'Logo Moderation Notifications', 'shelterkit-donations' ), 'sd_emails', 'email', [
            'default' => get_option( 'admin_email' ),
            'description' => __( 'Email address for business logo moderation notifications.', 'shelterkit-donations' ),
        ], 'emails' );

        // Data management — destructive, so off by default.
        self::add_field( 'delete_data_on_uninstall', __( 'Delete all data on uninstall', 'shelterkit-donations' ), 'sd_data', 'checkbox', [
            'default'     => false,
            'description' => __( 'When enabled, uninstalling the plugin permanently deletes all donations, memberships, memorials, donors, campaigns, and the activity log. Leave OFF to preserve your records — this cannot be undone. Before enabling, download a full backup from Import / Export. WooCommerce products and the media library are always kept.', 'shelterkit-donations' ),
        ], 'general' );
    }

    private static function add_field( string $id, string $title, string $section, string $type, array $args = [], string $tab = 'general' ): void {
        add_settings_field( $id, $title, [ self::class, 'render_field' ], self::PAGE_SLUG . '_' . $tab, $section,
            array_merge( $args, [ 'id' => $id, 'type' => $type ] )
        );
    }

    public static function render_field( array $args ): void {
        $options = get_option( self::OPTION_NAME, [] );
        $id      = $args['id'];
        $type    = $args['type'];
        $value   = $options[ $id ] ?? ( $args['default'] ?? '' );
        $name    = self::OPTION_NAME . '[' . $id . ']';

        switch ( $type ) {
            case 'text':
            case 'email':
            case 'url':
                printf( '<input type="%s" id="%s" name="%s" value="%s" class="regular-text" placeholder="%s" />',
                    esc_attr( $type ), esc_attr( $id ), esc_attr( $name ), esc_attr( $value ), esc_attr( $args['placeholder'] ?? '' ) );
                break;

            case 'number':
                printf( '<input type="number" id="%s" name="%s" value="%s" class="small-text" min="%s" max="%s" />',
                    esc_attr( $id ), esc_attr( $name ), esc_attr( $value ), esc_attr( $args['min'] ?? '' ), esc_attr( $args['max'] ?? '' ) );
                break;

            case 'textarea':
                printf( '<textarea id="%s" name="%s" rows="%d" class="large-text">%s</textarea>',
                    esc_attr( $id ), esc_attr( $name ), (int) ( $args['rows'] ?? 5 ), esc_textarea( $value ) );
                break;

            case 'select':
                echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';
                foreach ( $args['options'] ?? [] as $opt_val => $opt_label ) {
                    printf( '<option value="%s" %s>%s</option>', esc_attr( $opt_val ), selected( $value, $opt_val, false ), esc_html( $opt_label ) );
                }
                echo '</select>';
                break;

            case 'checkbox':
                printf( '<input type="checkbox" id="%s" name="%s" value="1" %s />', esc_attr( $id ), esc_attr( $name ), checked( $value, true, false ) );
                break;

            case 'page':
                wp_dropdown_pages( [ 'name' => $name, 'id' => $id, 'selected' => $value, 'show_option_none' => '— Select —', 'option_none_value' => 0 ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_dropdown_pages() escapes its own output; args are internal field identifiers.
                break;
        }

        if ( ! empty( $args['description'] ) ) {
            echo '<p class="description">' . esc_html( $args['description'] ) . '</p>';
        }
    }

    public static function sanitize_settings( array $input ): array {
        $sanitized = [];

        foreach ( [ 'email_from_name' ] as $f ) {
            $sanitized[ $f ] = sanitize_text_field( $input[ $f ] ?? '' );
        }

        // The organisation fields moved to the shared Shelter Details screen in
        // 3.1.0 and are no longer on this form. Carry the stored values forward
        // rather than reading $input: sanitise() rebuilds the option from
        // scratch, so anything not carried is deleted, and the receipt helpers
        // still fall back to these for installs that filled them in before the
        // profile existed.
        $existing = get_option( self::OPTION_NAME, [] );
        $existing = is_array( $existing ) ? $existing : [];
        foreach ( [ 'org_name', 'org_ein', 'org_phone', 'org_address' ] as $f ) {
            if ( isset( $existing[ $f ] ) ) {
                $sanitized[ $f ] = $existing[ $f ];
            }
        }
        foreach ( [ 'email_from_address', 'logo_moderation_email' ] as $f ) {
            $sanitized[ $f ] = sanitize_email( $input[ $f ] ?? '' );
        }
        $sanitized['fiscal_year_start_month'] = absint( $input['fiscal_year_start_month'] ?? 7 );
        $sanitized['renewal_reminder_days'] = min( 90, max( 7, absint( $input['renewal_reminder_days'] ?? 30 ) ) );
        $sanitized['donation_page']   = absint( $input['donation_page'] ?? 0 );
        $sanitized['membership_page'] = absint( $input['membership_page'] ?? 0 );

        foreach ( [ 'feature_anonymous_donations', 'feature_dedications', 'feature_family_notifications', 'feature_renewal_reminders', 'feature_annual_statements' ] as $f ) {
            $sanitized[ $f ] = ! empty( $input[ $f ] );
        }
        $sanitized['delete_data_on_uninstall'] = ! empty( $input['delete_data_on_uninstall'] );
        return $sanitized;
    }

    public static function get_defaults(): array {
        return [
            'fiscal_year_start_month'       => 7,
            'renewal_reminder_days'         => 30,
            'org_name'                      => get_bloginfo( 'name' ),
            'org_ein'                       => '',
            'org_address'                   => '',
            'org_phone'                     => '',
            'donation_page'                 => 0,
            'membership_page'               => 0,
            'email_from_name'               => get_bloginfo( 'name' ),
            'email_from_address'            => get_option( 'admin_email' ),
            'logo_moderation_email'         => get_option( 'admin_email' ),
            'feature_anonymous_donations'   => true,
            'feature_dedications'           => true,
            'feature_family_notifications'  => true,
            'feature_renewal_reminders'     => true,
            'feature_annual_statements'     => true,
            'delete_data_on_uninstall'      => false,
        ];
    }

    public static function render_settings_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $current_tab = self::get_current_tab();
        $message     = isset( $_GET['message'] ) ? sanitize_key( $_GET['message'] ) : '';
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

            <?php if ( 'data_saved' === $message ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Configuration saved. Sync check cache cleared.', 'shelterkit-donations' ); ?></p></div>
            <?php elseif ( 'products_created' === $message ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Products have been created successfully.', 'shelterkit-donations' ); ?></p></div>
            <?php elseif ( 'products_saved' === $message ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Product settings have been saved.', 'shelterkit-donations' ); ?></p></div>
            <?php endif; ?>

            <nav class="nav-tab-wrapper">
                <?php foreach ( self::$tabs as $tab_slug => $tab_label ) : ?>
                    <a href="<?php echo esc_url( add_query_arg( 'tab', $tab_slug, admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) ); ?>" 
                       class="nav-tab <?php echo $current_tab === $tab_slug ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html( __( $tab_label, 'shelterkit-donations' ) ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- tab label sourced from the self::$tabs config map, not a literal. ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="sd-settings-content" style="margin-top: 20px;">
                <?php
                if ( 'products' === $current_tab ) {
                    self::render_products_tab();
                } elseif ( 'data' === $current_tab ) {
                    self::render_data_tab();
                } else {
                    ?>
                    <form action="options.php" method="post">
                        <?php
                        settings_fields( self::OPTION_GROUP );
                        do_settings_sections( self::PAGE_SLUG . '_' . $current_tab );
                        submit_button( __( 'Save Settings', 'shelterkit-donations' ) );
                        ?>
                    </form>
                    <?php
                    if ( 'emails' === $current_tab ) {
                        self::render_email_templates_table();
                    }
                }
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render the email templates overview table.
     *
     * Shows all configured emails with status, subject, trigger info,
     * and links to the WooCommerce email settings for customization.
     *
     * @since 2.1.0
     */
    private static function render_email_templates_table(): void {
        $emails_config = Config::get_item( 'emails', 'emails', [] );

        if ( empty( $emails_config ) ) {
            return;
        }

        // Check if WooCommerce emails are available.
        $wc_emails = [];
        if ( function_exists( 'WC' ) && WC()->mailer() ) {
            foreach ( WC()->mailer()->get_emails() as $email ) {
                $wc_emails[ $email->id ] = $email;
            }
        }

        $recipient_labels = [
            'donor'  => __( 'Donor', 'shelterkit-donations' ),
            'admin'  => __( 'Admin', 'shelterkit-donations' ),
            'custom' => __( 'Custom', 'shelterkit-donations' ),
        ];
        ?>
        <hr style="margin: 30px 0;" />

        <h2><?php esc_html_e( 'Email Templates', 'shelterkit-donations' ); ?></h2>
        <p class="description"><?php esc_html_e( 'These emails are triggered automatically by plugin events. Click "Configure" to customize subjects, headings, and enable/disable individual emails in WooCommerce.', 'shelterkit-donations' ); ?></p>

        <table class="widefat striped" style="margin-top: 15px;">
            <thead>
                <tr>
                    <th style="width: 5%;"></th>
                    <th style="width: 20%;"><?php esc_html_e( 'Email', 'shelterkit-donations' ); ?></th>
                    <th style="width: 30%;"><?php esc_html_e( 'Subject', 'shelterkit-donations' ); ?></th>
                    <th style="width: 10%;"><?php esc_html_e( 'Recipient', 'shelterkit-donations' ); ?></th>
                    <th style="width: 20%;"><?php esc_html_e( 'Trigger', 'shelterkit-donations' ); ?></th>
                    <th style="width: 15%;"><?php esc_html_e( 'Actions', 'shelterkit-donations' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php
                foreach ( $emails_config as $email_id => $email_config ) :
                    $wc_id    = 'sd_' . str_replace( '-', '_', $email_id );
                    $wc_email = $wc_emails[ $wc_id ] ?? null;
                    $enabled  = $wc_email ? $wc_email->is_enabled() : true;
                    $subject  = $wc_email ? $wc_email->get_option( 'subject', $email_config['subject'] ?? '' ) : ( $email_config['subject'] ?? '' );
                    $recipient_type = $email_config['recipient_type'] ?? 'donor';
                    $settings_url   = admin_url( 'admin.php?page=wc-settings&tab=email&section=' . $wc_id );
					?>
                <tr<?php echo $enabled ? '' : ' style="opacity: 0.6;"'; ?>>
                    <td>
                        <?php if ( $enabled ) : ?>
                            <span class="dashicons dashicons-yes-alt" style="color: #00a32a;" title="<?php esc_attr_e( 'Enabled', 'shelterkit-donations' ); ?>"></span>
                        <?php else : ?>
                            <span class="dashicons dashicons-dismiss" style="color: #999;" title="<?php esc_attr_e( 'Disabled', 'shelterkit-donations' ); ?>"></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <strong><?php echo esc_html( $email_config['title'] ?? $email_id ); ?></strong>
                        <p class="description" style="margin: 2px 0 0;"><?php echo esc_html( $email_config['description'] ?? '' ); ?></p>
                    </td>
                    <td>
                        <code style="font-size: 12px; background: #f0f0f1; padding: 2px 6px; border-radius: 3px;"><?php echo esc_html( $subject ); ?></code>
                    </td>
                    <td>
                        <?php
                        echo esc_html( $recipient_labels[ $recipient_type ] ?? $recipient_type );
                        if ( ! empty( $email_config['condition'] ) ) {
                            echo ' <span class="dashicons dashicons-filter" style="font-size: 14px; color: #999;" title="' . esc_attr__( 'Conditional', 'shelterkit-donations' ) . '"></span>';
                        }
                        ?>
                    </td>
                    <td>
                        <code style="font-size: 11px;"><?php echo esc_html( $email_config['trigger_hook'] ?? '' ); ?></code>
                    </td>
                    <td>
                        <?php if ( $wc_email ) : ?>
                            <a href="<?php echo esc_url( $settings_url ); ?>" class="button button-small">
                                <?php esc_html_e( 'Configure', 'shelterkit-donations' ); ?>
                            </a>
                        <?php else : ?>
                            <span class="description"><?php esc_html_e( 'Not registered', 'shelterkit-donations' ); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ( ! empty( $wc_emails ) ) : ?>
        <p style="margin-top: 15px;">
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=email' ) ); ?>" class="button">
                <?php esc_html_e( 'WooCommerce Email Settings', 'shelterkit-donations' ); ?> →
            </a>
        </p>
        <?php endif; ?>
        <?php
    }

    /**
     * Render the Data configuration tab.
     *
     * Editable config values that override JSON defaults.
     *
     * @since 2.1.0
     */
    private static function render_data_tab(): void {
        $allocations  = Config::get_item( 'settings', 'allocations', [] );
        $all_tiers    = Config::get_item( 'tiers', 'tiers', [] );
        $ind_tiers    = $all_tiers['individual'] ?? [];
        $biz_tiers    = $all_tiers['business'] ?? [];
        $pet_species  = Config::get_item( 'settings', 'pet_species', [] );
        $donor_levels = Config::get_item( 'settings', 'donor_levels', [] );
        $thresholds   = $donor_levels['thresholds'] ?? [];
        ?>
        <form method="post">
            <?php wp_nonce_field( 'sd_data_config' ); ?>

            <!-- Allocations -->
            <h2><?php esc_html_e( 'Donation Allocations', 'shelterkit-donations' ); ?></h2>
            <p class="description"><?php esc_html_e( 'Fund designations that donors can direct their gifts to.', 'shelterkit-donations' ); ?></p>
            <table class="widefat striped sd-data-table" style="max-width: 600px; margin: 15px 0;">
                <thead>
                    <tr>
                        <th style="width: 40%;"><?php esc_html_e( 'Slug', 'shelterkit-donations' ); ?></th>
                        <th style="width: 50%;"><?php esc_html_e( 'Display Name', 'shelterkit-donations' ); ?></th>
                        <th style="width: 10%;"></th>
                    </tr>
                </thead>
                <tbody id="sd-allocations-list">
                    <?php foreach ( $allocations as $slug => $label ) : ?>
                    <tr>
                        <td><input type="text" name="sd_allocations[slugs][]" value="<?php echo esc_attr( $slug ); ?>" class="regular-text" /></td>
                        <td><input type="text" name="sd_allocations[labels][]" value="<?php echo esc_attr( $label ); ?>" class="regular-text" /></td>
                        <td><button type="button" class="button sd-remove-row" title="<?php esc_attr_e( 'Remove', 'shelterkit-donations' ); ?>">&times;</button></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <button type="button" class="button sd-add-row" data-target="sd-allocations-list" data-fields="sd_allocations"><?php esc_html_e( '+ Add Allocation', 'shelterkit-donations' ); ?></button>
            <?php if ( Config::has_override( 'settings', 'allocations' ) ) : ?>
                <span class="sd-override-badge"><?php esc_html_e( 'Custom', 'shelterkit-donations' ); ?></span>
            <?php endif; ?>

            <hr style="margin: 30px 0;" />

            <!-- Individual Tiers -->
            <h2><?php esc_html_e( 'Individual Membership Tiers', 'shelterkit-donations' ); ?></h2>
            <p class="description"><?php esc_html_e( 'Membership levels for individual supporters.', 'shelterkit-donations' ); ?></p>
            <table class="widefat striped sd-data-table" style="max-width: 700px; margin: 15px 0;">
                <thead>
                    <tr>
                        <th style="width: 25%;"><?php esc_html_e( 'Slug', 'shelterkit-donations' ); ?></th>
                        <th style="width: 40%;"><?php esc_html_e( 'Label', 'shelterkit-donations' ); ?></th>
                        <th style="width: 20%;"><?php esc_html_e( 'Price', 'shelterkit-donations' ); ?></th>
                        <th style="width: 15%;"></th>
                    </tr>
                </thead>
                <tbody id="sd-ind-tiers-list">
                    <?php foreach ( $ind_tiers as $slug => $tier ) : ?>
                    <tr>
                        <td><input type="text" name="sd_ind_tiers[slugs][]" value="<?php echo esc_attr( $slug ); ?>" class="regular-text" /></td>
                        <td><input type="text" name="sd_ind_tiers[labels][]" value="<?php echo esc_attr( $tier['label'] ?? '' ); ?>" class="regular-text" /></td>
                        <td><input type="number" name="sd_ind_tiers[amounts][]" value="<?php echo esc_attr( $tier['amount'] ?? 0 ); ?>" min="0" step="1" class="small-text" /></td>
                        <td><button type="button" class="button sd-remove-row">&times;</button></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <button type="button" class="button sd-add-row" data-target="sd-ind-tiers-list" data-fields="sd_ind_tiers"><?php esc_html_e( '+ Add Tier', 'shelterkit-donations' ); ?></button>

            <hr style="margin: 30px 0;" />

            <!-- Business Tiers -->
            <h2><?php esc_html_e( 'Business Membership Tiers', 'shelterkit-donations' ); ?></h2>
            <p class="description"><?php esc_html_e( 'Membership levels for business/corporate supporters.', 'shelterkit-donations' ); ?></p>
            <table class="widefat striped sd-data-table" style="max-width: 700px; margin: 15px 0;">
                <thead>
                    <tr>
                        <th style="width: 25%;"><?php esc_html_e( 'Slug', 'shelterkit-donations' ); ?></th>
                        <th style="width: 40%;"><?php esc_html_e( 'Label', 'shelterkit-donations' ); ?></th>
                        <th style="width: 20%;"><?php esc_html_e( 'Price', 'shelterkit-donations' ); ?></th>
                        <th style="width: 15%;"></th>
                    </tr>
                </thead>
                <tbody id="sd-biz-tiers-list">
                    <?php foreach ( $biz_tiers as $slug => $tier ) : ?>
                    <tr>
                        <td><input type="text" name="sd_biz_tiers[slugs][]" value="<?php echo esc_attr( $slug ); ?>" class="regular-text" /></td>
                        <td><input type="text" name="sd_biz_tiers[labels][]" value="<?php echo esc_attr( $tier['label'] ?? '' ); ?>" class="regular-text" /></td>
                        <td><input type="number" name="sd_biz_tiers[amounts][]" value="<?php echo esc_attr( $tier['amount'] ?? 0 ); ?>" min="0" step="1" class="small-text" /></td>
                        <td><button type="button" class="button sd-remove-row">&times;</button></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <button type="button" class="button sd-add-row" data-target="sd-biz-tiers-list" data-fields="sd_biz_tiers"><?php esc_html_e( '+ Add Tier', 'shelterkit-donations' ); ?></button>

            <hr style="margin: 30px 0;" />

            <!-- Pet Species -->
            <h2><?php esc_html_e( 'Pet Species', 'shelterkit-donations' ); ?></h2>
            <p class="description"><?php esc_html_e( 'Species options for pet memorials.', 'shelterkit-donations' ); ?></p>
            <table class="widefat striped sd-data-table" style="max-width: 500px; margin: 15px 0;">
                <thead>
                    <tr>
                        <th style="width: 40%;"><?php esc_html_e( 'Slug', 'shelterkit-donations' ); ?></th>
                        <th style="width: 50%;"><?php esc_html_e( 'Display Name', 'shelterkit-donations' ); ?></th>
                        <th style="width: 10%;"></th>
                    </tr>
                </thead>
                <tbody id="sd-species-list">
                    <?php foreach ( $pet_species as $slug => $label ) : ?>
                    <tr>
                        <td><input type="text" name="sd_species[slugs][]" value="<?php echo esc_attr( $slug ); ?>" class="regular-text" /></td>
                        <td><input type="text" name="sd_species[labels][]" value="<?php echo esc_attr( $label ); ?>" class="regular-text" /></td>
                        <td><button type="button" class="button sd-remove-row">&times;</button></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <button type="button" class="button sd-add-row" data-target="sd-species-list" data-fields="sd_species"><?php esc_html_e( '+ Add Species', 'shelterkit-donations' ); ?></button>

            <hr style="margin: 30px 0;" />

            <!-- Donor Levels -->
            <h2><?php esc_html_e( 'Donor Recognition Levels', 'shelterkit-donations' ); ?></h2>
            <p class="description"><?php esc_html_e( 'Lifetime giving thresholds for donor recognition tiers.', 'shelterkit-donations' ); ?></p>
            <table class="widefat striped sd-data-table" style="max-width: 500px; margin: 15px 0;">
                <thead>
                    <tr>
                        <th style="width: 50%;"><?php esc_html_e( 'Level Name', 'shelterkit-donations' ); ?></th>
                        <th style="width: 40%;"><?php esc_html_e( 'Minimum ($)', 'shelterkit-donations' ); ?></th>
                        <th style="width: 10%;"></th>
                    </tr>
                </thead>
                <tbody id="sd-donor-levels-list">
                    <?php foreach ( $thresholds as $level => $amount ) : ?>
                    <tr>
                        <td><input type="text" name="sd_donor_levels[names][]" value="<?php echo esc_attr( $level ); ?>" class="regular-text" /></td>
                        <td><input type="number" name="sd_donor_levels[amounts][]" value="<?php echo esc_attr( $amount ); ?>" min="0" step="1" class="small-text" /></td>
                        <td><button type="button" class="button sd-remove-row">&times;</button></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <button type="button" class="button sd-add-row" data-target="sd-donor-levels-list" data-fields="sd_donor_levels"><?php esc_html_e( '+ Add Level', 'shelterkit-donations' ); ?></button>

            <p class="submit">
                <button type="submit" name="sd_save_data_config" class="button button-primary"><?php esc_html_e( 'Save Data Configuration', 'shelterkit-donations' ); ?></button>
            </p>
        </form>

        <script>
        jQuery( function( $ ) {
            // Add row.
            $( '.sd-add-row' ).on( 'click', function() {
                var target = $( '#' + $( this ).data( 'target' ) );
                var fields = $( this ).data( 'fields' );
                var row = target.find( 'tr:last' ).clone();
                row.find( 'input' ).val( '' );
                target.append( row );
            } );

            // Remove row.
            $( document ).on( 'click', '.sd-remove-row', function() {
                var tbody = $( this ).closest( 'tbody' );
                if ( tbody.find( 'tr' ).length > 1 ) {
                    $( this ).closest( 'tr' ).remove();
                }
            } );
        } );
        </script>

        <style>
            .sd-data-table input.regular-text { width: 100%; }
            .sd-data-table input.small-text { width: 100px; }
            .sd-override-badge {
                display: inline-block;
                margin-left: 8px;
                padding: 2px 8px;
                background: #2271b1;
                color: #fff;
                border-radius: 3px;
                font-size: 11px;
                font-weight: 600;
                vertical-align: middle;
            }
        </style>
        <?php
    }

    /**
     * Save the Data tab configuration.
     *
     * @since 2.1.0
     */
    private static function save_data_tab(): void {
        // Allocations: slug → label map.
        // phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.InputNotValidated,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- called only from handle_product_actions(), which checks current_user_can( 'manage_woocommerce' ) and check_admin_referer( 'sd_data_config' ) before dispatching here.
        if ( isset( $_POST['sd_allocations']['slugs'] ) ) {
            $slugs  = array_map( 'sanitize_key', $_POST['sd_allocations']['slugs'] );
            $labels = array_map( 'sanitize_text_field', $_POST['sd_allocations']['labels'] );
            $allocations = [];
            foreach ( $slugs as $i => $slug ) {
                if ( ! empty( $slug ) && ! empty( $labels[ $i ] ) ) {
                    $allocations[ $slug ] = $labels[ $i ];
                }
            }
            if ( ! empty( $allocations ) ) {
                Config::save_override( 'settings', 'allocations', $allocations );
            }
        }

        // Individual tiers.
        if ( isset( $_POST['sd_ind_tiers']['slugs'] ) ) {
            $ind_tiers = self::parse_tier_input( $_POST['sd_ind_tiers'] );
            $biz_tiers = self::parse_tier_input( $_POST['sd_biz_tiers'] ?? [] );

            $tiers = [
                'individual' => $ind_tiers,
                'business'   => $biz_tiers,
            ];
            Config::save_override( 'tiers', 'tiers', $tiers );
        }

        // Pet species.
        if ( isset( $_POST['sd_species']['slugs'] ) ) {
            $slugs  = array_map( 'sanitize_key', $_POST['sd_species']['slugs'] );
            $labels = array_map( 'sanitize_text_field', $_POST['sd_species']['labels'] );
            $species = [];
            foreach ( $slugs as $i => $slug ) {
                if ( ! empty( $slug ) && ! empty( $labels[ $i ] ) ) {
                    $species[ $slug ] = $labels[ $i ];
                }
            }
            if ( ! empty( $species ) ) {
                Config::save_override( 'settings', 'pet_species', $species );
            }
        }

        // Donor levels.
        if ( isset( $_POST['sd_donor_levels']['names'] ) ) {
            $names   = array_map( 'sanitize_key', $_POST['sd_donor_levels']['names'] );
            $amounts = array_map( 'absint', $_POST['sd_donor_levels']['amounts'] );
        // phpcs:enable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.InputNotValidated,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
            $levels = [];
            foreach ( $names as $i => $name ) {
                if ( ! empty( $name ) ) {
                    $levels[ $name ] = $amounts[ $i ] ?? 0;
                }
            }
            if ( ! empty( $levels ) ) {
                Config::save_override( 'settings', 'donor_levels', [ 'thresholds' => $levels ] );
            }
        }

        // Clear sync checker cache since config changed.
        Product_Sync_Checker::invalidate_cache();
    }

    /**
     * Parse tier input from POST data.
     *
     * @since 2.1.0
     *
     * @param array $input POST data with slugs, labels, amounts arrays.
     * @return array Parsed tier data keyed by slug.
     */
    private static function parse_tier_input( array $input ): array {
        $slugs   = array_map( 'sanitize_key', $input['slugs'] ?? [] );
        $labels  = array_map( 'sanitize_text_field', $input['labels'] ?? [] );
        $amounts = array_map( 'absint', $input['amounts'] ?? [] );

        $tiers = [];
        foreach ( $slugs as $i => $slug ) {
            if ( empty( $slug ) || empty( $labels[ $i ] ) ) {
                continue;
            }
            $tiers[ $slug ] = [
                'slug'   => $slug,
                'label'  => $labels[ $i ],
                'amount' => $amounts[ $i ] ?? 0,
            ];
        }
        return $tiers;
    }

    private static function render_products_tab(): void {
        if ( ! class_exists( 'WooCommerce' ) ) {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'WooCommerce is required to manage donation products.', 'shelterkit-donations' ) . '</p></div>';
            return;
        }

        $product_status = Activator::get_product_status();
        $all_products   = self::get_all_wc_products();
        ?>
        <h2><?php esc_html_e( 'WooCommerce Product Configuration', 'shelterkit-donations' ); ?></h2>
        <p class="description"><?php esc_html_e( 'Configure which WooCommerce products are used for donations, memberships, and memorials.', 'shelterkit-donations' ); ?></p>

        <div style="margin: 20px 0; padding: 15px; background: #f0f0f1; border-left: 4px solid #2271b1;">
            <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( [ 'page' => self::PAGE_SLUG, 'tab' => 'products', 'action' => 'create_products' ], admin_url( 'admin.php' ) ), 'sd_create_products' ) ); ?>" class="button button-primary">
                <?php esc_html_e( 'Auto-Create Missing Products', 'shelterkit-donations' ); ?>
            </a>
            <p class="description" style="margin-top: 10px;"><?php esc_html_e( 'Creates variable WooCommerce products with standard variations for any missing products.', 'shelterkit-donations' ); ?></p>
        </div>

        <form method="post">
            <?php wp_nonce_field( 'sd_product_mappings' ); ?>
            
            <table class="widefat striped" style="margin-top: 20px;">
                <thead>
                    <tr>
                        <th style="width: 25%;"><?php esc_html_e( 'Product Type', 'shelterkit-donations' ); ?></th>
                        <th style="width: 15%;"><?php esc_html_e( 'Status', 'shelterkit-donations' ); ?></th>
                        <th style="width: 40%;"><?php esc_html_e( 'WooCommerce Product', 'shelterkit-donations' ); ?></th>
                        <th style="width: 20%;"><?php esc_html_e( 'Actions', 'shelterkit-donations' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $product_status as $key => $status ) : ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html( $status['name'] ); ?></strong>
                                <p class="description"><?php echo esc_html( self::get_product_description( $key ) ); ?></p>
                            </td>
                            <td>
                                <?php if ( $status['exists'] ) : ?>
                                    <span style="color: #00a32a;"><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Configured', 'shelterkit-donations' ); ?></span>
                                <?php else : ?>
                                    <span style="color: #dba617;"><span class="dashicons dashicons-warning"></span> <?php esc_html_e( 'Not Set', 'shelterkit-donations' ); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <select name="<?php echo esc_attr( $status['option_key'] ); ?>" style="width: 100%; max-width: 400px;">
                                    <option value="0"><?php esc_html_e( '— Select Product —', 'shelterkit-donations' ); ?></option>
                                    <?php foreach ( $all_products as $product ) : ?>
                                        <option value="<?php echo esc_attr( $product['id'] ); ?>" <?php selected( $status['product_id'], $product['id'] ); ?>>
                                            <?php echo esc_html( $product['name'] . ' (' . ( $product['sku'] ?: 'No SKU' ) . ')' ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <?php if ( $status['exists'] && $status['edit_url'] ) : ?>
                                    <a href="<?php echo esc_url( $status['edit_url'] ); ?>" class="button button-small" target="_blank">
                                        <?php esc_html_e( 'Edit', 'shelterkit-donations' ); ?> <span class="dashicons dashicons-external" style="font-size: 14px; line-height: 1.8;"></span>
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <p class="submit">
                <button type="submit" name="sd_save_product_mappings" class="button button-primary"><?php esc_html_e( 'Save Product Settings', 'shelterkit-donations' ); ?></button>
            </p>
        </form>

        <div style="margin-top: 30px; padding: 20px; background: #fff; border: 1px solid #c3c4c7;">
            <h3 style="margin-top: 0;"><?php esc_html_e( 'Product Requirements', 'shelterkit-donations' ); ?></h3>
            <p><?php esc_html_e( 'Each product type requires a Variable Product with specific attributes:', 'shelterkit-donations' ); ?></p>
            
            <table class="widefat striped" style="margin-top: 15px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Product', 'shelterkit-donations' ); ?></th>
                        <th><?php esc_html_e( 'SKU', 'shelterkit-donations' ); ?></th>
                        <th><?php esc_html_e( 'Attribute', 'shelterkit-donations' ); ?></th>
                        <th><?php esc_html_e( 'Variations', 'shelterkit-donations' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td>Shelter Donations</td><td><code>shelterkit-donations</code></td><td>Preferred Allocation</td><td>General Fund, Medical Care, etc.</td></tr>
                    <tr><td>Individual Memberships</td><td><code>shelter-memberships</code></td><td>Membership Level</td><td>Single ($10) - Benefactor ($1000)</td></tr>
                    <tr><td>Business Memberships</td><td><code>shelter-memberships-business</code></td><td>Membership Level</td><td>Contributing ($50) - Benefactor ($1000)</td></tr>
                    <tr><td>Memorial Donations</td><td><code>shelterkit-donations-in-memoriam</code></td><td>In Memoriam Type</td><td>Person, Pet</td></tr>
                </tbody>
            </table>
        </div>
        <?php
    }

    private static function get_product_description( string $key ): string {
        return [
            'shelterkit-donations'             => __( 'General donations with allocation options.', 'shelterkit-donations' ),
            'shelter-memberships'           => __( 'Individual membership tiers.', 'shelterkit-donations' ),
            'shelter-memberships-business'  => __( 'Business/corporate membership tiers.', 'shelterkit-donations' ),
            'shelterkit-donations-in-memoriam' => __( 'Memorial donations for people or pets.', 'shelterkit-donations' ),
        ][ $key ] ?? '';
    }

    private static function get_all_wc_products(): array {
        if ( ! function_exists( 'wc_get_products' ) ) {
            return [];
        }

        $result = [];
        foreach ( wc_get_products( [ 'type' => 'variable', 'status' => 'publish', 'limit' => -1 ] ) as $product ) {
            $result[] = [ 'id' => $product->get_id(), 'name' => $product->get_name(), 'sku' => $product->get_sku() ];
        }
        return $result;
    }

    /**
     * Move the organisation fields into the shared ShelterKit profile, once.
     *
     * 3.0.0 adopted the shared Shelter Details screen but left this plugin's
     * own Organization fields in place beside it, so the same information had
     * two homes and nothing said which the receipts read. The fields are gone
     * from the form as of 3.1.0; this carries what an install already had.
     *
     * Only fills profile fields that are empty, so a shelter that has already
     * filled in Shelter Details is never overwritten. The source values are
     * left in place: the receipt helpers still fall back to them, and keeping
     * them makes this safe to re-run.
     *
     * @since 3.1.0
     */
    public static function maybe_migrate_organization_to_profile(): void {
        if ( get_option( 'sd_org_profile_migrated' ) ) {
            return;
        }
        if ( ! class_exists( 'ShelterKit_Profile' ) ) {
            return; // Profile not loaded yet; try again on the next request.
        }

        $legacy = [
            'name'   => (string) self::get( 'org_name', '' ),
            'phone'  => (string) self::get( 'org_phone', '' ),
            'tax_id' => (string) self::get( 'org_ein', '' ),
        ];

        // org_address is one free-text textarea and the profile stores a
        // structured address, so it cannot be split reliably. Put it in
        // street_address only when the whole profile address is empty — the
        // shelter can then break it apart by hand. Discarding it would be
        // worse: it is the only copy.
        // The RAW stored option, not ShelterKit_Profile::all(): all() substitutes
        // the site title for an unset name, so an "is this already filled in?"
        // test against it would never see name as empty and would skip it.
        $profile = get_option( \ShelterKit_Profile::OPTION, [] );
        $profile = is_array( $profile ) ? $profile : [];

        $address_empty = '' === trim(
            ( $profile['street_address'] ?? '' ) . ( $profile['locality'] ?? '' )
            . ( $profile['region'] ?? '' ) . ( $profile['postal_code'] ?? '' )
        );
        if ( $address_empty ) {
            $legacy['street_address'] = (string) self::get( 'org_address', '' );
        }

        $to_write = [];
        foreach ( $legacy as $field => $value ) {
            if ( '' !== trim( $value ) && '' === trim( $profile[ $field ] ?? '' ) ) {
                $to_write[ $field ] = $value;
            }
        }

        // The site title is ShelterKit_Profile::all()'s fallback for an unset
        // name, so an org_name equal to it carries no information.
        if ( isset( $to_write['name'] ) && $to_write['name'] === get_bloginfo( 'name' ) ) {
            unset( $to_write['name'] );
        }

        if ( $to_write ) {
            \ShelterKit_Profile::save( $to_write );
        }

        update_option( 'sd_org_profile_migrated', 1 );
    }

    public static function get( string $key, $default = null ) {
        $options  = get_option( self::OPTION_NAME, [] );
        $defaults = self::get_defaults();
        return $options[ $key ] ?? $defaults[ $key ] ?? $default;
    }

    public static function is_feature_enabled( string $feature ): bool {
        return (bool) self::get( 'feature_' . $feature, true );
    }
}
