<?php
/**
 * Field manifest for `sd_donation`.
 *
 * Source of truth for every place a sd_donation field name appears.
 * Loaded by Field_Manifest; merged into entities/abilities/products/
 * meta-boxes/checkout-fields by their respective Config flows.
 *
 * @package Starter_Shelter
 * @subpackage Manifests
 * @since 1.1.2
 *
 * @see Starter_Shelter\Core\Field_Manifest
 */

declare( strict_types = 1 );

return [
	'entity'      => 'sd_donation',
	'meta_prefix' => '_sd_',
	'fields'      => [
		'donor_id' => [
			'type'        => 'integer',
			'description' => 'ID of the associated donor',
			'form'        => [
				'label'      => 'Donor',
				'input_type' => 'post_select',
				'post_type'  => 'sd_donor',
			],
		],
		'amount' => [
			'type'        => 'number',
			'minimum'     => 0,
			'description' => 'Donation amount',
			'form'        => [
				'label'      => 'Amount',
				'input_type' => 'currency',
				'required'   => true,
			],
		],
		'allocation' => [
			'type'        => 'string',
			'enum'        => [ 'general-fund', 'spay-neuter-clinic' ],
			'default'     => 'general-fund',
			'description' => 'Fund allocation',
			'form'        => [
				'label'      => 'Allocation',
				'input_type' => 'select',
				// Options resolved at render time from Config::get_item('settings', 'allocations').
				'options'    => 'allocations',
			],
		],
		'is_anonymous' => [
			'type'        => 'boolean',
			'default'     => false,
			'description' => 'Whether donation is anonymous',
			'form'        => [
				'label'      => 'Anonymous Donation',
				'input_type' => 'checkbox',
			],
		],
		'dedication' => [
			'type'        => 'string',
			'maxLength'   => 500,
			'description' => 'Optional dedication message',
			'form'        => [
				'label'      => 'Dedication Message',
				'input_type' => 'textarea',
				'rows'       => 3,
			],
		],
		'donation_date' => [
			'type'        => 'string',
			'format'      => 'date-time',
			'description' => 'Date of donation',
			'form'        => [
				'label'      => 'Donation Date',
				'input_type' => 'datetime',
				'default'    => 'now',
			],
		],
		'wc_order_id' => [
			'type'        => 'integer',
			'description' => 'Associated WooCommerce order ID',
			'form'        => [
				'label'      => 'WooCommerce Order',
				'input_type' => 'order_link',
				'readonly'   => true,
			],
		],
	],
	'computed' => [
		'amount_formatted' => [
			'function' => 'format_currency',
			'args'     => [ 'amount' ],
		],
		'date_formatted' => [
			'function' => 'format_date',
			'args'     => [ 'donation_date' ],
		],
		'allocation_label' => [
			'function' => 'get_allocation_label',
			'args'     => [ 'allocation' ],
		],
		'donor_name' => [
			'function' => 'get_donor_display_name',
			'args'     => [ 'donor_id', 'is_anonymous' ],
		],
	],
	'relations' => [
		'donor' => [
			'type'        => 'sd_donor',
			'foreign_key' => 'donor_id',
		],
		'campaign' => [
			'type'     => 'taxonomy',
			'taxonomy' => 'sd_campaign',
		],
	],

	/*
	 * Abilities owned by this entity. See sd_membership.php for the
	 * `$entity` ref convention.
	 */
	'abilities' => [
		'shelter-donations/create' => [
			'label'       => 'Create Donation',
			'description' => 'Creates a new donation record from a WooCommerce order or direct input',
			'permission'  => 'internal',
			'meta'        => [
				'annotations'  => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ],
				'show_in_rest' => false,
			],
			'input' => [
				'required' => [ 'amount' ],
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
						'type'        => 'string',
						'format'      => 'email',
						'description' => "Donor's email address",
					],
					'donor_name' => [
						'type'        => 'string',
						'description' => "Donor's full name",
					],
					// Stricter minimum than the entity's 0 — abilities reject zero-amount donations.
					'amount'       => [ '$entity' => 'amount', 'minimum' => 0.01 ],
					'order_id' => [
						'type'        => 'integer',
						'description' => 'WooCommerce order ID',
					],
					'allocation'   => [ '$entity' => 'allocation' ],
					'is_anonymous' => [ '$entity' => 'is_anonymous' ],
					'dedication'   => [ '$entity' => 'dedication' ],
					'campaign_id' => [
						'type'        => 'integer',
						'description' => 'Campaign term ID',
					],
					'donation_date' => [
						'$entity'     => 'donation_date',
						'description' => 'Donation date. Defaults to current time. Legacy callers may pass `date` instead.',
					],
				],
			],
			'output' => [
				'required'   => [ 'donation_id', 'donor_id', 'status' ],
				'properties' => [
					'donation_id' => [
						'type'        => 'integer',
						'description' => 'Created donation post ID',
					],
					'donor_id' => [
						'$entity'     => 'donor_id',
						'description' => 'Donor post ID',
					],
					'status' => [
						'type'        => 'string',
						'description' => 'Operation status',
					],
				],
			],
		],

		'shelter-donations/get' => [
			'label'       => 'Get Donation',
			'description' => 'Retrieves a single donation by ID',
			'permission'  => 'owner_or_admin',
			'meta'        => [
				'annotations'  => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
				'show_in_rest' => true,
			],
			'input' => [
				'required'   => [ 'donation_id' ],
				'properties' => [
					'donation_id' => [
						'type'        => 'integer',
						'description' => 'Donation post ID',
					],
				],
			],
			'output' => [
				// abilities.json had `output_schema: { "type": "object", "description": "Hydrated donation entity" }`
				// — no `properties`, just a top-level description. The projector
				// passes the description through.
				'description' => 'Hydrated donation entity',
			],
		],

		'shelter-donations/list' => [
			'label'       => 'List Donations',
			'description' => 'Retrieves a paginated list of donations with filters',
			'callback'    => 'Starter_Shelter\\Abilities\\Donations\\list_donations',
			'permission'  => 'owner_or_admin',
			'meta'        => [
				'annotations'  => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
				'show_in_rest' => true,
			],
			'input' => [
				'properties' => [
					// Filter shapes diverge from entity field shapes (no
					// enum/default — filters accept anything). Local
					// declarations rather than $entity refs.
					'donor_id' => [
						'type'        => 'integer',
						'description' => 'Filter by donor',
					],
					'allocation' => [
						'type'        => 'string',
						'description' => 'Filter by allocation',
					],
					'campaign_id' => [
						'type'        => 'integer',
						'description' => 'Filter by campaign',
					],
					'date_from' => [
						'type'        => 'string',
						'format'      => 'date',
						'description' => 'Start date filter',
					],
					'date_to' => [
						'type'        => 'string',
						'format'      => 'date',
						'description' => 'End date filter',
					],
					'page' => [
						'type'    => 'integer',
						'minimum' => 1,
						'default' => 1,
					],
					'per_page' => [
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 100,
						'default' => 10,
					],
				],
			],
			'output' => [
				'properties' => [
					'items'       => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
					'total'       => [ 'type' => 'integer' ],
					'total_pages' => [ 'type' => 'integer' ],
					'page'        => [ 'type' => 'integer' ],
				],
			],
		],

		'shelter-donations/get-stats' => [
			'label'       => 'Get Donation Statistics',
			'description' => 'Retrieves aggregated donation statistics for a period',
			'permission'  => 'admin',
			'meta'        => [
				'annotations'  => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
				'show_in_rest' => true,
			],
			'input' => [
				'properties' => [
					'period' => [
						'type'    => 'string',
						'enum'    => [ 'fiscal_year', 'calendar_year', 'month', 'quarter', 'all_time' ],
						'default' => 'fiscal_year',
					],
					'fiscal_year' => [
						'type'        => 'integer',
						'description' => 'Specific fiscal year',
					],
				],
			],
			'output' => [
				'properties' => [
					'total_amount'    => [ 'type' => 'number' ],
					'total_formatted' => [ 'type' => 'string' ],
					'donation_count'  => [ 'type' => 'integer' ],
					'donor_count'     => [ 'type' => 'integer' ],
					'average_amount'  => [ 'type' => 'number' ],
				],
			],
		],
	],

	/*
	 * WooCommerce products owned by this entity (see sd_membership.php
	 * for shape rationale).
	 */
	'products' => [
		'shelter-donations' => [
			'ability'       => 'shelter-donations/create',
			'product_type'  => 'donation',
			'description'   => 'General shelter donations',
			'input_mapping' => [
				'allocation' => [
					'source'    => 'attribute',
					'key'       => 'preferred-allocation',
					'transform' => 'normalize_allocation',
					'default'   => 'general-fund',
				],
				'donor_name' => [
					'source' => 'item_meta',
					'key'    => '_sd_donor_name',
				],
				'dedication' => [
					'source'   => 'item_meta',
					'key'      => '_sd_dedication',
					'fallback' => [
						'source' => 'order_meta',
						'key'    => '_sd_dedication',
					],
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
				'campaign_id' => [
					'source'   => 'item_meta',
					'key'      => '_sd_campaign_id',
					'fallback' => [
						'source' => 'order_meta',
						'key'    => '_sd_campaign_id',
					],
				],
			],
		],
	],

	/*
	 * WooCommerce checkout fields (see sd_membership.php for rationale).
	 */
	'checkout_fields' => [
		'dedication' => [
			// Override the field's textarea/admin form: at checkout, dedication
			// renders as a short text input with a different label.
			'input_type'    => 'text',
			'label'         => 'Dedication (optional)',
			'placeholder'   => 'In honor of...',
			'required'      => false,
			'priority'      => 20,
			'class'         => [ 'form-row-wide' ],
			'product_types' => [ 'donation' ],
		],
		// campaign_id lives as a taxonomy relation on the entity, not a
		// meta field; this overlay is self-contained.
		'campaign_id' => [
			'input_type'    => 'select',
			'label'         => 'Support a Campaign (optional)',
			'options'       => 'campaigns',
			'required'      => false,
			'priority'      => 30,
			'class'         => [ 'form-row-wide' ],
			'product_types' => [ 'donation' ],
		],
	],

	/*
	 * Admin meta boxes (see sd_membership.php for rationale).
	 */
	'meta_boxes' => [
		'donation_details' => [
			'title'    => 'Donation Details',
			'context'  => 'normal',
			'priority' => 'high',
			'fields'   => [
				'amount',
				'donor_id',
				'donation_date',
				'allocation',
				'is_anonymous',
				'dedication',
			],
		],
		'order_info' => [
			'title'   => 'Order Information',
			'context' => 'side',
			'fields'  => [ 'wc_order_id' ],
		],
	],

	/*
	 * WooCommerce email definitions owned by this entity. Same shape as
	 * the legacy `emails.json.emails` entries. Placeholder values are
	 * dot-paths into hydrated entity_data; the validator walks them
	 * against the referenced entities' manifests.
	 */
	'emails' => [
		'donation-receipt' => [
			'title'         => 'Donation Receipt',
			'description'   => 'Sent to donors after a successful donation',
			'trigger_hook'  => 'starter_shelter_donation_created',
			// Typed trigger_args. `input` $ability_input-refs the
			// shelter-donations/create input schema, so future
			// args.input.X placeholders validate against the
			// ability's declared input properties.
			'trigger_args'  => [
				'donation_id' => [ 'type' => 'integer' ],
				'donor_id'    => [ 'type' => 'integer' ],
				'input'       => [ '$ability_input' => 'shelter-donations/create' ],
			],
			'entities'      => [
				'donation' => [ 'entity' => 'sd_donation', 'id_from' => 'donation_id' ],
				'donor'    => [ 'entity' => 'sd_donor',    'id_from' => 'donor_id' ],
			],
			'recipient_type' => 'donor',
			'subject'        => 'Thank you for your donation to {site_name}!',
			'heading'        => 'Thank You for Your Generosity!',
			'template'       => 'emails/donation-receipt.php',
			'placeholders'   => [
				'donor_name' => 'donor.full_name',
				'amount'     => 'donation.amount_formatted',
				'allocation' => 'donation.allocation_label',
				'date'       => 'donation.date_formatted',
			],
		],
	],
];
