<?php
/**
 * Field manifest for `sd_membership`.
 *
 * Source of truth for every place a sd_membership field name appears.
 * Loaded by Field_Manifest; merged into the entities config by
 * Config::get('entities') so existing Entity_Hydrator consumers
 * transparently see this content.
 *
 * Per-layer extension points (form, ability_io, products_input, etc.)
 * are introduced incrementally — see Field_Manifest::SCHEMA_VERSION.
 *
 * @package Starter_Shelter
 * @subpackage Manifests
 * @since 1.1.2
 *
 * @see Starter_Shelter\Core\Field_Manifest
 */

declare( strict_types = 1 );

return [
	'entity'      => 'sd_membership',
	'meta_prefix' => '_sd_',
	'fields'      => [
		'donor_id' => [
			'type'        => 'integer',
			'description' => 'ID of the associated donor',
		],
		'tier' => [
			'type'        => 'string',
			'description' => 'Membership tier slug',
		],
		'membership_type' => [
			'type'        => 'string',
			'enum'        => [ 'individual', 'family', 'business' ],
			'default'     => 'individual',
			'description' => 'Type of membership',
		],
		'amount' => [
			'type'        => 'number',
			'minimum'     => 0,
			'description' => 'Membership amount',
		],
		'start_date' => [
			'type'        => 'string',
			'format'      => 'date',
			'description' => 'Membership start date',
		],
		'end_date' => [
			'type'        => 'string',
			'format'      => 'date',
			'description' => 'Membership expiration date',
		],
		'wc_order_id' => [
			'type'        => 'integer',
			'description' => 'Associated WooCommerce order ID',
		],
		'auto_renew' => [
			'type'        => 'boolean',
			'default'     => false,
			'description' => 'Whether membership auto-renews',
		],
		'business_name' => [
			'type'        => 'string',
			'description' => 'Business name (business memberships only)',
		],
		'business_website' => [
			'type'        => 'string',
			'format'      => 'uri',
			'description' => 'Business website URL (business memberships only)',
		],
		'business_description' => [
			'type'        => 'string',
			'description' => 'Business description (business memberships only)',
		],
		'logo_attachment_id' => [
			'type'        => 'integer',
			'description' => 'WordPress attachment ID of the business logo',
		],
		'logo_status' => [
			'type'        => 'string',
			'enum'        => [ 'pending', 'approved', 'rejected' ],
			'description' => 'Moderation status of the business logo',
		],
		'logo_rejection_reason' => [
			'type'        => 'string',
			'description' => 'Reason given when a business logo is rejected',
		],
		'status' => [
			'type'        => 'string',
			'enum'        => [ 'active', 'cancelled', 'expired' ],
			'default'     => 'active',
			'description' => 'Lifecycle status of the membership',
		],
		'cancelled_at' => [
			'type'        => 'string',
			'format'      => 'date-time',
			'description' => 'When the membership was cancelled (if applicable)',
		],
	],
	'computed' => [
		'tier_label' => [
			'function' => 'get_tier_label',
			'args'     => [ 'tier' ],
		],
		'is_active' => [
			'function' => 'is_membership_active',
			'args'     => [ 'end_date' ],
		],
		'is_expiring_soon' => [
			'function' => 'is_membership_expiring_soon',
			'args'     => [ 'end_date' ],
		],
		'days_remaining' => [
			'function' => 'get_days_until',
			'args'     => [ 'end_date' ],
		],
		'amount_formatted' => [
			'function' => 'format_currency',
			'args'     => [ 'amount' ],
		],
	],
	'relations' => [
		'donor' => [
			'type'        => 'sd_donor',
			'foreign_key' => 'donor_id',
		],
	],
];
