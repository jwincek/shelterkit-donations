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
];
