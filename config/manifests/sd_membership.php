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
			'form'        => [
				'label'      => 'Member',
				'input_type' => 'post_select',
				'post_type'  => 'sd_donor',
			],
		],
		'tier' => [
			'type'        => 'string',
			'description' => 'Membership tier slug',
			'form'        => [
				'label'      => 'Tier',
				'input_type' => 'tier_select',
			],
		],
		'membership_type' => [
			'type'        => 'string',
			'enum'        => [ 'individual', 'family', 'business' ],
			'default'     => 'individual',
			'description' => 'Type of membership',
			'form'        => [
				'label'      => 'Type',
				'input_type' => 'select',
				// admin-only subset of the entity enum; "family" is a valid
				// value at the entity level but not currently editable.
				'options'    => [ 'individual' => 'Individual', 'business' => 'Business' ],
			],
		],
		'amount' => [
			'type'        => 'number',
			'minimum'     => 0,
			'description' => 'Membership amount',
			'form'        => [
				'label'      => 'Amount Paid',
				'input_type' => 'currency',
			],
		],
		'start_date' => [
			'type'        => 'string',
			'format'      => 'date',
			'description' => 'Membership start date',
			'form'        => [
				'label'      => 'Start Date',
				'input_type' => 'date',
			],
		],
		'end_date' => [
			'type'        => 'string',
			'format'      => 'date',
			'description' => 'Membership expiration date',
			'form'        => [
				'label'      => 'End Date',
				'input_type' => 'date',
			],
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
			'form'        => [
				'label'      => 'Business Name',
				'input_type' => 'text',
			],
		],
		'business_website' => [
			'type'        => 'string',
			'format'      => 'uri',
			'description' => 'Business website URL (business memberships only)',
			'form'        => [
				'label'      => 'Website',
				'input_type' => 'url',
			],
		],
		'business_description' => [
			'type'        => 'string',
			'description' => 'Business description (business memberships only)',
			'form'        => [
				'label'      => 'Description',
				'input_type' => 'textarea',
				'rows'       => 3,
			],
		],
		'logo_attachment_id' => [
			'type'        => 'integer',
			'description' => 'WordPress attachment ID of the business logo',
			'form'        => [
				'label'      => 'Business Logo',
				'input_type' => 'image',
			],
		],
		'logo_status' => [
			'type'        => 'string',
			'enum'        => [ 'pending', 'approved', 'rejected' ],
			'description' => 'Moderation status of the business logo',
			'form'        => [
				'label'      => 'Logo Status',
				'input_type' => 'status_badge',
				'readonly'   => true,
			],
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

	/*
	 * Abilities owned by this entity. Each property uses one of two forms:
	 *
	 *  - `[ '$entity' => 'field_name', ...overrides ]` — pull the field's
	 *    shape from `fields` above; sibling keys override entity values.
	 *  - `[ 'type' => ..., ... ]` — ability-local declaration (no $entity ref).
	 *
	 * Refs target `fields` only, not `computed`. The validator catches
	 * dangling $entity refs and required-not-in-properties typos.
	 *
	 * Prefer $entity refs over local declarations whenever the property
	 * refers to a real entity field — that's the manifest's whole point:
	 * one source of truth for each field's shape. Use local declarations
	 * only for ability-specific parameters (e.g., `reason` on cancel,
	 * `page`/`per_page` on list) or for computed-field-derived output
	 * (since refs don't target `computed`).
	 */
	'abilities' => [
		'shelter-memberships/create' => [
			'label'       => 'Create Membership',
			'description' => 'Creates a new membership record',
			'permission'  => 'internal',
			'meta'        => [
				'annotations'  => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ],
				'show_in_rest' => false,
			],
			'input' => [
				'required' => [ 'tier', 'amount' ],
				'oneOf' => [
					[ 'required' => [ 'donor_id' ] ],
					[ 'required' => [ 'donor_email' ] ],
				],
				'properties' => [
					'donor_id' => [
						'$entity'     => 'donor_id',
						'description' => 'Pre-resolved donor post ID. If provided, donor_email lookup is skipped.',
					],
					'donor_email' => [
						'type'   => 'string',
						'format' => 'email',
					],
					'donor_name' => [
						'type' => 'string',
					],
					'tier'            => [ '$entity' => 'tier' ],
					'membership_type' => [ '$entity' => 'membership_type' ],
					'amount'          => [ '$entity' => 'amount' ],
					'order_id' => [
						'type' => 'integer',
					],
					'business_name' => [
						'$entity'     => 'business_name',
						'description' => 'Business name for business memberships',
					],
					'logo_attachment_id' => [
						'$entity'     => 'logo_attachment_id',
						'description' => 'WordPress attachment ID of the business logo (business memberships)',
					],
					'is_anonymous' => [
						'type'    => 'boolean',
						'default' => false,
					],
				],
			],
			'output' => [
				'properties' => [
					'membership_id' => [ 'type' => 'integer' ],
					'donor_id'      => [ '$entity' => 'donor_id' ],
					'start_date'    => [ '$entity' => 'start_date' ],
					'end_date'      => [ '$entity' => 'end_date' ],
					'status'        => [ '$entity' => 'status' ],
				],
			],
		],

		'shelter-memberships/renew' => [
			'label'       => 'Renew Membership',
			'description' => 'Renews an existing membership',
			'permission'  => 'internal',
			'meta'        => [
				'annotations'  => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ],
				'show_in_rest' => false,
			],
			'input' => [
				'required'   => [ 'membership_id', 'amount', 'order_id' ],
				'properties' => [
					'membership_id' => [ 'type' => 'integer' ],
					'amount'        => [ '$entity' => 'amount' ],
					'order_id'      => [ 'type' => 'integer' ],
				],
			],
			'output' => [
				'properties' => [
					'membership_id' => [ 'type' => 'integer' ],
					// new_end_date is the renewed end_date value reported by
					// the ability response; not stored under that name on
					// the entity, but shares the entity's date shape.
					'new_end_date'  => [ '$entity' => 'end_date', 'description' => 'New end date after renewal' ],
					'status'        => [ '$entity' => 'status' ],
				],
			],
		],

		'shelter-memberships/get-status' => [
			'label'       => 'Get Membership Status',
			'description' => 'Retrieves current membership status for a donor',
			'permission'  => 'owner_or_admin',
			'meta'        => [
				'annotations'  => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
				'show_in_rest' => true,
			],
			'input' => [
				'required'   => [ 'donor_id' ],
				'properties' => [
					'donor_id' => [ 'type' => 'integer' ],
				],
			],
			'output' => [
				'properties' => [
					// is_active / tier_label / is_expiring_soon / days_remaining
					// derive from `computed`; $entity refs target `fields` only.
					'is_active'        => [ 'type' => 'boolean' ],
					'tier'             => [ '$entity' => 'tier' ],
					'tier_label'       => [ 'type' => 'string' ],
					'end_date'         => [ '$entity' => 'end_date' ],
					'is_expiring_soon' => [ 'type' => 'boolean' ],
					'days_remaining'   => [ 'type' => 'integer' ],
				],
			],
		],

		'shelter-memberships/list' => [
			'label'       => 'List Memberships',
			'description' => 'Retrieves a paginated list of memberships',
			'callback'    => 'Starter_Shelter\\Abilities\\Memberships\\list_memberships',
			'permission'  => 'admin',
			'meta'        => [
				'annotations'  => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
				'show_in_rest' => true,
			],
			'input' => [
				'properties' => [
					'status' => [
						'type'    => 'string',
						'enum'    => [ 'active', 'expired', 'expiring_soon', 'all' ],
						'default' => 'all',
					],
					'tier'            => [ 'type' => 'string' ],
					'membership_type' => [ 'type' => 'string' ],
					'page'            => [ 'type' => 'integer', 'default' => 1 ],
					'per_page'        => [ 'type' => 'integer', 'default' => 10 ],
				],
			],
			'output' => [
				'properties' => [
					'items'       => [ 'type' => 'array' ],
					'total'       => [ 'type' => 'integer' ],
					'total_pages' => [ 'type' => 'integer' ],
					'page'        => [ 'type' => 'integer' ],
				],
			],
		],

		'shelter-memberships/cancel' => [
			'label'       => 'Cancel Membership',
			'description' => 'Cancels an active membership',
			'permission'  => 'owner_or_admin',
			'meta'        => [
				'annotations'  => [ 'readonly' => false, 'destructive' => true, 'idempotent' => true ],
				'show_in_rest' => true,
			],
			'input' => [
				'required'   => [ 'membership_id' ],
				'properties' => [
					'membership_id' => [
						'type'        => 'integer',
						'description' => 'Membership post ID',
					],
					'reason' => [
						'type'        => 'string',
						'maxLength'   => 500,
						'description' => 'Optional cancellation reason',
					],
				],
			],
			'output' => [
				'properties' => [
					'membership_id' => [ 'type' => 'integer' ],
					'status'        => [ '$entity' => 'status' ],
					'cancelled_at'  => [ '$entity' => 'cancelled_at' ],
				],
			],
		],
	],

	/*
	 * WooCommerce products owned by this entity. Each entry mirrors the
	 * shape under `products.<sku_prefix>` in products.json: the cart-to-
	 * order-to-ability mapping the Cart_Handler / Product_Mapper /
	 * Order_Handler chain consumes.
	 *
	 * `input_mapping` keys are field names that flow into the ability's
	 * input_schema; the validator's check_product_input_mappings ensures
	 * each one exists in the referenced ability's declared input.
	 *
	 * Install-state data (legacy_products.product_ids[]) and cross-product
	 * configuration (sku_attribute_mapping, checkout_field_sets) stay in
	 * products.json since they're not single-entity definitions.
	 */
	'products' => [
		'shelter-memberships' => [
			'ability'       => 'shelter-memberships/create',
			'product_type'  => 'membership',
			'description'   => 'Individual shelter memberships',
			'input_mapping' => [
				'tier' => [
					'source'    => 'attribute',
					'key'       => 'membership-level',
					'transform' => 'normalize_tier',
				],
				'membership_type' => [
					'source' => 'static',
					'value'  => 'individual',
				],
				'donor_name' => [
					'source' => 'item_meta',
					'key'    => '_sd_donor_name',
				],
				'is_anonymous' => [
					'source'    => 'item_meta',
					'key'       => '_sd_is_anonymous',
					'fallback'  => [
						'source' => 'order_meta',
						'key'    => '_sd_is_anonymous',
					],
					'transform' => 'boolean',
					'default'   => false,
				],
			],
		],

		'shelter-memberships-business' => [
			'ability'       => 'shelter-memberships/create',
			'product_type'  => 'membership',
			'description'   => 'Business shelter memberships',
			'input_mapping' => [
				'tier' => [
					'source'    => 'attribute',
					'key'       => 'membership-level',
					'transform' => 'normalize_tier',
				],
				'membership_type' => [
					'source' => 'static',
					'value'  => 'business',
				],
				'donor_name' => [
					'source' => 'item_meta',
					'key'    => '_sd_donor_name',
				],
				'business_name' => [
					'source'   => 'item_meta',
					'key'      => '_sd_business_name',
					'fallback' => [
						'source'   => 'order_meta',
						'key'      => '_sd_business_name',
						'fallback' => [
							'source' => 'order_field',
							'key'    => 'billing_company',
						],
					],
				],
				'logo_attachment_id' => [
					'source'    => 'item_meta',
					'key'       => '_sd_logo_attachment_id',
					'transform' => 'integer',
				],
				'is_anonymous' => [
					'source'    => 'item_meta',
					'key'       => '_sd_is_anonymous',
					'fallback'  => [
						'source' => 'order_meta',
						'key'    => '_sd_is_anonymous',
					],
					'transform' => 'boolean',
					'default'   => false,
				],
			],
		],

		'memberships' => [
			'ability'       => 'shelter-memberships/create',
			'product_type'  => 'membership',
			'description'   => 'Legacy membership product (pre-existing SKU)',
			'legacy'        => true,
			'input_mapping' => [
				'tier' => [
					'source'    => 'attribute',
					'key'       => 'membership-level',
					'transform' => 'normalize_tier',
				],
				'membership_type' => [
					'source' => 'static',
					'value'  => 'individual',
				],
			],
		],
	],

	/*
	 * WooCommerce checkout fields. Each entry overlays per-checkout
	 * UI attributes (placeholder, required, priority, class,
	 * product_types, conditional) onto the field's intrinsic `form`
	 * shape declared in `fields.<name>.form` above. The projector
	 * derives `meta_key` from `meta_prefix + field_name` and produces
	 * a config compatible with Checkout_Fields::render_field's
	 * dispatch.
	 *
	 * Common fields (is_anonymous, etc.) are not entity-owned and
	 * remain in the hard-coded array inside Checkout_Fields until a
	 * shared-field mechanism exists.
	 */
	'checkout_fields' => [
		'business_name' => [
			'placeholder'   => 'Your business or organization name',
			'required'      => true,
			'priority'      => 10,
			'class'         => [ 'form-row-wide' ],
			'product_types' => [ 'business_membership' ],
		],
	],

	/*
	 * Admin meta boxes for the CPT edit screen. Each box lists fields
	 * by name; the field's UI shape comes from `fields.<name>.form`
	 * above. A `name => overrides` entry in the field list overrides
	 * the intrinsic form attributes per-box.
	 *
	 * Box-level `show_when` hides the entire box conditionally; field-
	 * level `show_when` lives on the field's `form` block (none used
	 * for sd_membership today). The projector produces a config shape
	 * compatible with Meta_Boxes::render_field's switch on `type`.
	 */
	'meta_boxes' => [
		'membership_details' => [
			'title'    => 'Membership Details',
			'context'  => 'normal',
			'priority' => 'high',
			'fields'   => [
				'donor_id',
				'membership_type',
				'tier',
				'amount',
				'start_date',
				'end_date',
			],
		],
		'business_info' => [
			'title'     => 'Business Information',
			'context'   => 'normal',
			'show_when' => [ 'membership_type' => 'business' ],
			'fields'    => [
				'business_name',
				'business_website',
				'business_description',
				'logo_attachment_id',
				'logo_status',
			],
		],
	],

	/*
	 * WooCommerce email definitions owned by this entity. See
	 * sd_donation.php for the placeholder validation convention.
	 */
	'emails' => [
		'membership-welcome' => [
			'title'         => 'Membership Welcome',
			'description'   => 'Sent to new members after joining',
			'trigger_hook'  => 'starter_shelter_membership_created',
			// Typed trigger_args. `input` $ability_input-refs the
			// shelter-memberships/create input schema for DRY
			// documentation and future placeholder validation.
			'trigger_args'  => [
				'membership_id' => [ 'type' => 'integer' ],
				'donor_id'      => [ 'type' => 'integer' ],
				'input'         => [ '$ability_input' => 'shelter-memberships/create' ],
			],
			'entities'      => [
				'membership' => [ 'entity' => 'sd_membership', 'id_from' => 'membership_id' ],
				'donor'      => [ 'entity' => 'sd_donor',      'id_from' => 'donor_id' ],
			],
			'recipient_type' => 'donor',
			'subject'        => 'Welcome to the {site_name} family!',
			'heading'        => 'Welcome, New Member!',
			'template'       => 'emails/membership-welcome.php',
			'placeholders'   => [
				'donor_name'  => 'donor.full_name',
				'tier'        => 'membership.tier_label',
				'expiry_date' => 'membership.end_date',
			],
		],

		'membership-renewal' => [
			'title'         => 'Membership Renewal Reminder',
			'description'   => 'Sent when membership is expiring soon',
			'trigger_hook'  => 'starter_shelter_membership_expiring',
			'trigger_args'  => [ 'membership_id', 'donor_id' ],
			'entities'      => [
				'membership' => [ 'entity' => 'sd_membership', 'id_from' => 'membership_id' ],
				'donor'      => [ 'entity' => 'sd_donor',      'id_from' => 'donor_id' ],
			],
			'recipient_type' => 'donor',
			'subject'        => 'Your {site_name} membership expires soon',
			'heading'        => 'Time to Renew Your Membership',
			'template'       => 'emails/membership-renewal.php',
			'placeholders'   => [
				'donor_name'     => 'donor.full_name',
				'tier'           => 'membership.tier_label',
				'expiry_date'    => 'membership.end_date',
				'days_remaining' => 'membership.days_remaining',
			],
		],

		'logo-approved' => [
			'title'         => 'Business Logo Approved',
			'description'   => 'Sent when a business membership logo is approved',
			'trigger_hook'  => 'starter_shelter_logo_approved',
			'trigger_args'  => [ 'membership_id', 'donor_id' ],
			'entities'      => [
				'membership' => [ 'entity' => 'sd_membership', 'id_from' => 'membership_id' ],
				'donor'      => [ 'entity' => 'sd_donor',      'id_from' => 'donor_id' ],
			],
			'recipient_type' => 'donor',
			'subject'        => 'Your business logo has been approved - {site_name}',
			'heading'        => 'Logo Approved!',
			'template'       => 'emails/logo-approved.php',
			'placeholders'   => [
				'donor_name'    => 'donor.full_name',
				'business_name' => 'membership.business_name',
			],
		],

		'logo-rejected' => [
			'title'         => 'Business Logo Rejected',
			'description'   => 'Sent when a business membership logo is rejected',
			'trigger_hook'  => 'starter_shelter_logo_rejected',
			'trigger_args'  => [ 'membership_id', 'donor_id', 'reason' ],
			'entities'      => [
				'membership' => [ 'entity' => 'sd_membership', 'id_from' => 'membership_id' ],
				'donor'      => [ 'entity' => 'sd_donor',      'id_from' => 'donor_id' ],
			],
			'recipient_type' => 'donor',
			'subject'        => 'Action required: Your business logo needs attention - {site_name}',
			'heading'        => 'Logo Update Required',
			'template'       => 'emails/logo-rejected.php',
			'placeholders'   => [
				'donor_name'       => 'donor.full_name',
				'business_name'    => 'membership.business_name',
				'rejection_reason' => 'args.reason',
			],
		],
	],
];
