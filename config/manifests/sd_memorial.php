<?php
/**
 * Field manifest for `sd_memorial`.
 *
 * Source of truth for every place a sd_memorial field name appears.
 * Loaded by Field_Manifest; merged into entities/abilities/products/
 * meta-boxes/checkout-fields by their respective Config flows.
 *
 * sd_memorial is the third migrated entity. Notable shape:
 *
 * - Five flat `notify_family_*` entity fields are the canonical
 *   storage for family-notification settings; a sibling
 *   `notify_family` field (type: object) is preserved for the legacy
 *   nested-meta read path (`Helpers\get_memorial_notify_family`).
 *   The audit's CC-2 drift between these and the checkout-fields'
 *   `family_*` shorthand keys is preserved verbatim — see the
 *   checkout_fields block below.
 * - The ability `shelter-memorials/create.input.notify_family`
 *   uses a `$ref` to `schemas/notify-family.json`. The manifest
 *   passes the ref through; Config::resolve_refs resolves it after
 *   the manifest merge.
 * - `products.shelter-donations-in-memoriam.input_mapping.notify_family`
 *   uses `source: composite` to combine the five flat meta keys into
 *   one structured value for the ability call. Mapping shape is a
 *   verbatim copy — Cart_Handler / Product_Mapper consume it natively.
 *
 * @package Starter_Shelter
 * @subpackage Manifests
 * @since 1.1.2
 *
 * @see Starter_Shelter\Core\Field_Manifest
 */

declare( strict_types = 1 );

return [
	'entity'      => 'sd_memorial',
	'meta_prefix' => '_sd_',
	'fields'      => [
		'donor_id' => [
			'type'         => 'integer',
			'show_in_rest' => true,
			'description'  => 'ID of the donor who created the memorial',
			'form'         => [
				'label'      => 'Donated By',
				'input_type' => 'post_select',
				'post_type'  => 'sd_donor',
			],
		],
		'donor_display_name' => [
			'type'         => 'string',
			'show_in_rest' => true,
			'description'  => 'Denormalized donor name for search (empty if anonymous)',
			'form'         => [
				'label'       => 'Display Name',
				'input_type'  => 'text',
				'description' => 'Name shown on the memorial wall. Leave empty to pull from donor record.',
			],
		],
		'honoree_name' => [
			'type'         => 'string',
			'maxLength'    => 200,
			'show_in_rest' => true,
			'description'  => 'Name of person or pet being honored',
			'form'         => [
				'label'      => 'Honoree Name',
				'input_type' => 'text',
				'required'   => true,
			],
		],
		'memorial_type' => [
			'type'         => 'string',
			'enum'         => [ 'person', 'pet' ],
			'default'      => 'person',
			'show_in_rest' => true,
			'description'  => "Subject of the memorial (canonical values; legacy rows may contain 'human', 'honor', or 'memory' — normalized to 'person' on read via Helpers\\normalize_memorial_type)",
			'form'         => [
				'label'      => 'Type',
				'input_type' => 'select',
				// Canonical options matching the entity enum. Legacy rows
				// stored with 'human'/'honor'/'memory' values are
				// normalized to 'person' on read via
				// Helpers\normalize_memorial_type; an admin re-saving such
				// a row writes the canonical value back.
				'options'    => [ 'person' => 'Person', 'pet' => 'Pet' ],
			],
		],
		'pet_species' => [
			'type'         => 'string',
			'show_in_rest' => true,
			'description'  => 'Species if memorial is for a pet',
			'form'         => [
				'label'      => 'Pet Species',
				'input_type' => 'select',
				'options'    => [
					'dog'   => 'Dog',
					'cat'   => 'Cat',
					'bird'  => 'Bird',
					'horse' => 'Horse',
					'other' => 'Other',
				],
				'show_when'  => [ 'memorial_type' => 'pet' ],
			],
		],
		'tribute_message' => [
			'type'         => 'string',
			'maxLength'    => 2000,
			'show_in_rest' => true,
			'description'  => 'Tribute message for the memorial',
			'form'         => [
				'label'      => 'Tribute Message',
				'input_type' => 'textarea',
				'rows'       => 6,
			],
		],
		'amount' => [
			'type'         => 'number',
			'minimum'      => 0,
			'show_in_rest' => true,
			'description'  => 'Donation amount',
			'form'         => [
				'label'      => 'Amount',
				'input_type' => 'currency',
			],
		],
		'is_anonymous' => [
			'type'         => 'boolean',
			'default'      => false,
			'show_in_rest' => true,
			'description'  => 'Whether donation is anonymous',
			'form'         => [
				'label'      => 'Anonymous',
				'input_type' => 'checkbox',
			],
		],
		'donation_date' => [
			'type'         => 'string',
			'format'       => 'date-time',
			'show_in_rest' => true,
			'description'  => 'Date of memorial donation',
			'form'         => [
				'label'      => 'Date',
				'input_type' => 'date',
			],
		],
		'wc_order_id' => [
			'type'         => 'integer',
			'show_in_rest' => true,
			'description'  => 'Associated WooCommerce order ID',
		],
		// Legacy nested-storage placeholder; canonical storage is the
		// five flat notify_family_* fields below. Use
		// Helpers\get_memorial_notify_family($id) for a read-both view.
		// Sub-properties match config/schemas/notify-family.json so that
		// email placeholders like `memorial.notify_family.enabled` can
		// be validated recursively by the manifest checker.
		'notify_family' => [
			'type'        => 'object',
			'description' => 'Legacy: nested family-notification meta. Canonical storage is the five flat notify_family_* fields below. Use Helpers\\get_memorial_notify_family($id) for a read-both view.',
			'properties'  => [
				'enabled' => [
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'Whether to send notification to family',
				],
				'name' => [
					'type'        => 'string',
					'maxLength'   => 100,
					'description' => 'Name of family member to notify',
				],
				'email' => [
					'type'        => 'string',
					'format'      => 'email',
					'description' => 'Email address for notification',
				],
				'address' => [
					'type'        => 'string',
					'maxLength'   => 500,
					'description' => 'Mailing address for physical card notification',
				],
				'send_card' => [
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'Whether to send a physical card',
				],
			],
		],
		'notify_family_enabled' => [
			'type'         => 'boolean',
			'default'      => false,
			'show_in_rest' => true,
			'description'  => 'Whether to send notification to family',
			'form'         => [
				'label'      => 'Notify Family',
				'input_type' => 'checkbox',
			],
		],
		'notify_family_name' => [
			'type'         => 'string',
			'show_in_rest' => true,
			'description'  => 'Name of family member to notify',
			'form'         => [
				'label'      => 'Family Name',
				'input_type' => 'text',
				'show_when'  => [ 'notify_family_enabled' => true ],
			],
		],
		'notify_family_email' => [
			'type'         => 'string',
			'show_in_rest' => true,
			'description'  => 'Email address for family notification',
			'form'         => [
				'label'      => 'Family Email',
				'input_type' => 'email',
				'show_when'  => [ 'notify_family_enabled' => true ],
			],
		],
		'notify_family_address' => [
			'type'         => 'string',
			'show_in_rest' => true,
			'description'  => 'Mailing address for family notification card',
			'form'         => [
				'label'      => 'Family Address',
				'input_type' => 'textarea',
				'rows'       => 2,
				'show_when'  => [ 'notify_family_enabled' => true ],
			],
		],
		'notify_family_send_card' => [
			'type'         => 'boolean',
			'default'      => false,
			'show_in_rest' => true,
			'description'  => 'Whether to send a physical card in addition to email',
			'form'         => [
				'label'      => 'Send physical card',
				'input_type' => 'checkbox',
				'show_when'  => [ 'notify_family_enabled' => true ],
			],
		],
		'family_notified_date' => [
			'type'         => 'string',
			'show_in_rest' => true,
			'description'  => 'Date when family notification was sent',
			'form'         => [
				'label'      => 'Notification Sent',
				'input_type' => 'datetime_display',
				'readonly'   => true,
			],
		],
		'photo_id' => [
			'type'         => 'integer',
			'show_in_rest' => true,
			'description'  => 'Attachment ID for memorial photo',
		],
		'candle_count' => [
			'type'        => 'integer',
			'default'     => 0,
			'description' => 'Number of candles lit for this memorial',
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
		'photo_url' => [
			'function' => 'get_attachment_url',
			'args'     => [ 'photo_id', 'medium' ],
		],
		'donor_name' => [
			'function' => 'get_memorial_donor_name',
			'args'     => [ 'is_anonymous', 'donor_display_name', 'donor_id' ],
		],
		'memorial_type_label' => [
			'function' => 'get_memorial_type_label',
			'args'     => [ 'memorial_type' ],
		],
	],
	'relations' => [
		'donor' => [
			'type'        => 'sd_donor',
			'foreign_key' => 'donor_id',
		],
	],

	'abilities' => [
		'shelter-memorials/create' => [
			'label'       => 'Create Memorial',
			'description' => 'Creates a new memorial donation',
			'permission'  => 'internal',
			'meta'        => [
				'annotations'  => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ],
				'show_in_rest' => false,
			],
			'input' => [
				'required' => [ 'honoree_name', 'memorial_type', 'amount' ],
				'oneOf' => [
					[ 'required' => [ 'donor_id' ] ],
					[ 'required' => [ 'donor_email' ] ],
				],
				'properties' => [
					'donor_id' => [
						'$entity'     => 'donor_id',
						'description' => 'Pre-resolved donor post ID. If provided, donor_email lookup is skipped.',
					],
					'donor_email' => [ 'type' => 'string', 'format' => 'email' ],
					'donor_name'  => [ 'type' => 'string' ],
					'honoree_name'    => [ '$entity' => 'honoree_name' ],
					'memorial_type' => [
						// Ability input has the canonical enum but no default.
						'type' => 'string',
						'enum' => [ 'person', 'pet' ],
					],
					'pet_species'     => [ '$entity' => 'pet_species' ],
					'tribute_message' => [ '$entity' => 'tribute_message' ],
					'amount'          => [ '$entity' => 'amount' ],
					'order_id' => [ 'type' => 'integer' ],
					'is_anonymous'    => [ '$entity' => 'is_anonymous' ],
					// Resolved by Config::resolve_refs after manifest merge.
					'notify_family' => [ '$ref' => 'schemas/notify-family.json' ],
					'campaign_id' => [
						'type'        => 'integer',
						'description' => 'Optional campaign term ID to attach this memorial to (per-item).',
					],
					'donation_date' => [
						'$entity'     => 'donation_date',
						'description' => 'Memorial date. Defaults to current time. Legacy callers may pass `date` instead.',
					],
				],
			],
			'output' => [
				'properties' => [
					'memorial_id'     => [ 'type' => 'integer' ],
					'donor_id'        => [ 'type' => 'integer' ],
					'honoree_name'    => [ '$entity' => 'honoree_name' ],
					'permalink'       => [ 'type' => 'string' ],
					'family_notified' => [ 'type' => 'boolean' ],
					'status'          => [ 'type' => 'string' ],
				],
			],
		],

		'shelter-memorials/list' => [
			'label'       => 'List Memorials',
			'description' => 'Retrieves a paginated list of memorials for the public wall',
			'callback'    => 'Starter_Shelter\\Abilities\\Memorials\\list_memorials',
			'permission'  => 'public',
			'meta'        => [
				'annotations'  => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
				'show_in_rest' => true,
			],
			'input' => [
				'properties' => [
					'type' => [
						'type'    => 'string',
						'enum'    => [ 'all', 'person', 'pet' ],
						'default' => 'all',
					],
					'year'     => [ 'type' => 'integer' ],
					'search'   => [ 'type' => 'string' ],
					'donor_id' => [ 'type' => 'integer' ],
					'page'     => [ 'type' => 'integer', 'default' => 1 ],
					'per_page' => [ 'type' => 'integer', 'default' => 12 ],
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

		'shelter-memorials/get' => [
			'label'       => 'Get Memorial',
			'description' => 'Retrieves a single memorial by ID',
			'permission'  => 'public',
			'meta'        => [
				'annotations'  => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
				'show_in_rest' => true,
			],
			'input' => [
				'required'   => [ 'memorial_id' ],
				'properties' => [
					'memorial_id' => [ 'type' => 'integer', 'description' => 'Memorial post ID' ],
				],
			],
			'output' => [
				'description' => 'Hydrated memorial entity with donor info',
			],
		],
	],

	'products' => [
		'shelter-donations-in-memoriam' => [
			'ability'       => 'shelter-memorials/create',
			'product_type'  => 'memorial',
			'description'   => 'In memoriam donations',
			'input_mapping' => [
				'memorial_type' => [
					'source'    => 'attribute',
					'key'       => 'in-memoriam-type',
					'transform' => 'lowercase',
				],
				'donor_name' => [
					'source' => 'item_meta',
					'key'    => '_sd_donor_name',
				],
				'honoree_name' => [
					'source'   => 'item_meta',
					'key'      => '_sd_honoree_name',
					'fallback' => [ 'source' => 'order_meta', 'key' => '_sd_honoree_name' ],
				],
				'tribute_message' => [
					'source'   => 'item_meta',
					'key'      => '_sd_tribute_message',
					'fallback' => [ 'source' => 'order_meta', 'key' => '_sd_tribute_message' ],
				],
				'pet_species' => [
					'source'   => 'item_meta',
					'key'      => '_sd_pet_species',
					'fallback' => [ 'source' => 'order_meta', 'key' => '_sd_pet_species' ],
				],
				'is_anonymous' => [
					'source'    => 'item_meta',
					'key'       => '_sd_is_anonymous',
					'fallback'  => [ 'source' => 'order_meta', 'key' => '_sd_is_anonymous' ],
					'transform' => 'boolean',
					'default'   => false,
				],
				'campaign_id' => [
					'source'   => 'item_meta',
					'key'      => '_sd_campaign_id',
					'fallback' => [ 'source' => 'order_meta', 'key' => '_sd_campaign_id' ],
				],
				// Composite mapping — combines five flat meta keys into
				// the notify_family object passed to the ability. The
				// Cart_Handler/Product_Mapper handles `source: composite`
				// natively. Reads the canonical `_sd_notify_family_*` keys
				// the cart-handler now writes; falls back to the legacy
				// `_sd_family_*` keys for in-flight orders placed before
				// the rename landed.
				'notify_family' => [
					'source' => 'composite',
					'fields' => [
						'enabled' => [
							'source'    => 'item_meta',
							'key'       => '_sd_notify_family_enabled',
							'fallback'  => [
								'source'   => 'item_meta',
								'key'      => '_sd_notify_family',
								'fallback' => [ 'source' => 'order_meta', 'key' => '_sd_notify_family' ],
							],
							'transform' => 'boolean',
							'default'   => false,
						],
						'name' => [
							'source'   => 'item_meta',
							'key'      => '_sd_notify_family_name',
							'fallback' => [
								'source'   => 'item_meta',
								'key'      => '_sd_family_name',
								'fallback' => [ 'source' => 'order_meta', 'key' => '_sd_family_name' ],
							],
						],
						'email' => [
							'source'   => 'item_meta',
							'key'      => '_sd_notify_family_email',
							'fallback' => [
								'source'   => 'item_meta',
								'key'      => '_sd_family_email',
								'fallback' => [ 'source' => 'order_meta', 'key' => '_sd_family_email' ],
							],
						],
						'address' => [
							'source'   => 'item_meta',
							'key'      => '_sd_notify_family_address',
							'fallback' => [
								'source'   => 'item_meta',
								'key'      => '_sd_family_address',
								'fallback' => [ 'source' => 'order_meta', 'key' => '_sd_family_address' ],
							],
						],
						'send_card' => [
							'source'    => 'item_meta',
							'key'       => '_sd_notify_family_send_card',
							'fallback'  => [
								'source'   => 'item_meta',
								'key'      => '_sd_send_card',
								'fallback' => [ 'source' => 'order_meta', 'key' => '_sd_send_card' ],
							],
							'transform' => 'boolean',
							'default'   => false,
						],
					],
				],
			],
		],
	],

	/*
	 * Checkout fields. The family-notification entries now use the
	 * canonical `notify_family_*` names matching the entity fields, so
	 * they can $entity-ref instead of being self-contained (closes the
	 * audit-flagged CC-2 cart/entity meta-key divergence). The cart-
	 * handler and the products composite mapping were updated to use
	 * the same canonical keys; the products mapping retains a fallback
	 * to the legacy `_sd_family_*` keys for in-flight orders.
	 */
	'checkout_fields' => [
		'honoree_name' => [
			// Checkout uses a more specific label than the admin meta-box.
			'label'         => 'Name of Person or Pet Being Honored',
			'placeholder'   => 'Enter name',
			'required'      => true,
			'priority'      => 10,
			'class'         => [ 'form-row-wide' ],
			'product_types' => [ 'memorial' ],
		],
		'pet_species' => [
			'label'         => 'Species (if pet)',
			'options'       => [
				''       => 'Not applicable / Human',
				'dog'    => 'Dog',
				'cat'    => 'Cat',
				'bird'   => 'Bird',
				'rabbit' => 'Rabbit',
				'other'  => 'Other',
			],
			'required'      => false,
			'priority'      => 15,
			'class'         => [ 'form-row-wide' ],
			'product_types' => [ 'memorial' ],
		],
		'tribute_message' => [
			'placeholder'   => 'Share a memory or message...',
			'required'      => false,
			'priority'      => 20,
			'class'         => [ 'form-row-wide' ],
			'product_types' => [ 'memorial' ],
		],
		'notify_family_enabled' => [
			'label'         => 'Notify family of this tribute',
			'description'   => 'We will send a card to the family letting them know of your gift.',
			'required'      => false,
			'priority'      => 30,
			'class'         => [ 'form-row-wide' ],
			'product_types' => [ 'memorial' ],
		],
		'notify_family_name' => [
			'label'         => 'Family Member Name',
			'required'      => false,
			'priority'      => 31,
			'class'         => [ 'form-row-wide', 'sd-family-field' ],
			'product_types' => [ 'memorial' ],
			'conditional'   => 'notify_family_enabled',
		],
		'notify_family_email' => [
			'label'         => 'Family Email (for digital notification)',
			'required'      => false,
			'priority'      => 32,
			'class'         => [ 'form-row-first', 'sd-family-field' ],
			'product_types' => [ 'memorial' ],
			'conditional'   => 'notify_family_enabled',
		],
		'notify_family_address' => [
			'label'         => 'Family Address (for mailed card)',
			'placeholder'   => 'Street address, City, State, ZIP',
			'required'      => false,
			'priority'      => 33,
			'class'         => [ 'form-row-wide', 'sd-family-field' ],
			'product_types' => [ 'memorial' ],
			'conditional'   => 'notify_family_enabled',
		],
		'notify_family_send_card' => [
			'label'         => 'Send a physical card (in addition to email)',
			'required'      => false,
			'priority'      => 34,
			'class'         => [ 'form-row-wide', 'sd-family-field' ],
			'product_types' => [ 'memorial' ],
			'conditional'   => 'notify_family_enabled',
		],
	],

	'meta_boxes' => [
		'memorial_details' => [
			'title'    => 'Memorial Details',
			'context'  => 'normal',
			'priority' => 'high',
			'fields'   => [
				'honoree_name',
				'memorial_type',
				'pet_species',
				'tribute_message',
			],
		],
		'donation_info' => [
			'title'   => 'Donation Info',
			'context' => 'side',
			'fields'  => [
				'donor_id',
				'donor_display_name',
				'amount',
				'donation_date',
				'is_anonymous',
			],
		],
		'family_notification' => [
			'title'   => 'Family Notification',
			'context' => 'normal',
			'fields'  => [
				'notify_family_enabled',
				'notify_family_name',
				'notify_family_email',
				'family_notified_date',
			],
		],
	],

	/*
	 * WooCommerce email definitions owned by this entity. See
	 * sd_donation.php for the placeholder validation convention.
	 *
	 * `memorial-family-notification` placeholders reference paths into
	 * the composite `notify_family` field — validator walks the
	 * properties sub-tree declared on that field above.
	 */
	'emails' => [
		'memorial-confirmation' => [
			'title'         => 'Memorial Donation Confirmation',
			'description'   => 'Sent to donors after creating a memorial',
			'trigger_hook'  => 'starter_shelter_memorial_created',
			'trigger_args'  => [
				'memorial_id' => [ 'type' => 'integer' ],
				'donor_id'    => [ 'type' => 'integer' ],
			],
			'entities'      => [
				'memorial' => [ 'entity' => 'sd_memorial', 'id_from' => 'memorial_id' ],
				'donor'    => [ 'entity' => 'sd_donor',    'id_from' => 'donor_id' ],
			],
			'recipient_type' => 'donor',
			'subject'        => 'Your memorial for {honoree_name} has been created',
			'heading'        => 'Memorial Created',
			'template'       => 'emails/memorial-confirmation.php',
			'placeholders'   => [
				'donor_name'   => 'donor.full_name',
				'honoree_name' => 'memorial.honoree_name',
				'amount'       => 'memorial.amount_formatted',
			],
		],

		'memorial-family-notification' => [
			'title'           => 'Memorial Family Notification',
			'description'     => 'Sent to family members when a memorial is created in honor of their loved one',
			'trigger_hook'    => 'starter_shelter_memorial_created',
			'trigger_args'    => [
				'memorial_id' => [ 'type' => 'integer' ],
				'donor_id'    => [ 'type' => 'integer' ],
			],
			'entities'        => [
				'memorial' => [ 'entity' => 'sd_memorial', 'id_from' => 'memorial_id' ],
				'donor'    => [ 'entity' => 'sd_donor',    'id_from' => 'donor_id' ],
			],
			'condition'       => 'memorial.notify_family.enabled',
			'recipient_type'  => 'custom',
			'recipient_field' => 'memorial.notify_family.email',
			'subject'         => 'A donation has been made in memory of {honoree_name}',
			'heading'         => 'A Special Tribute',
			'template'        => 'emails/memorial-family-notification.php',
			'placeholders'    => [
				'honoree_name'    => 'memorial.honoree_name',
				'donor_name'      => 'donor.full_name',
				'tribute_message' => 'memorial.tribute_message',
			],
		],
	],
];
