<?php
/**
 * Field manifest for `sd_donor`.
 *
 * The fourth and final entity. Has no products or checkout-fields of
 * its own (sd_donor is a profile entity, not purchasable), but owns:
 *
 * - 4 abilities (get-profile, update-address, get-history, update-profile)
 * - 4 meta-boxes (contact_info, address, donor_stats, user_account)
 * - 1 email (donor-annual-summary)
 *
 * Notable shapes:
 *
 * - The `address` field is declared as `type: object` with no
 *   sub-properties. Update-address abilities use `$ref` to
 *   `schemas/address.json` for the canonical address shape; the
 *   admin meta-box edits five flat sub-fields (`address_line_1`,
 *   etc.) under separate meta keys — those entries are self-
 *   contained in the meta-box block (no matching entity field).
 *   Audit-tracked drift; preserved verbatim.
 * - `donor_stats` meta-box renders `donor_level` (a computed),
 *   `donation_count` (no entity declaration — stored as `_sd_donation_count`
 *   meta directly), and read-only display variants of
 *   `lifetime_giving` / `first_donation_date`. Display-only entries
 *   declare their own input_type in-overlay.
 *
 * @package Starter_Shelter
 * @subpackage Manifests
 * @since 1.1.2
 *
 * @see Starter_Shelter\Core\Field_Manifest
 */

declare( strict_types = 1 );

return [
	'entity'      => 'sd_donor',
	'meta_prefix' => '_sd_',
	'fields'      => [
		'email' => [
			'type'        => 'string',
			'format'      => 'email',
			'description' => 'Donor email address',
			'form'        => [
				'label'      => 'Email',
				'input_type' => 'email',
				'required'   => true,
			],
		],
		'display_name' => [
			'type'        => 'string',
			'maxLength'   => 200,
			'description' => 'Donor display name',
		],
		'first_name' => [
			'type'        => 'string',
			'maxLength'   => 100,
			'description' => 'Donor first name',
			'form'        => [
				'label'      => 'First Name',
				'input_type' => 'text',
				'required'   => true,
			],
		],
		'last_name' => [
			'type'        => 'string',
			'maxLength'   => 100,
			'description' => 'Donor last name',
			'form'        => [
				'label'      => 'Last Name',
				'input_type' => 'text',
				'required'   => true,
			],
		],
		'phone' => [
			'type'        => 'string',
			'maxLength'   => 30,
			'description' => 'Donor phone number',
			'form'        => [
				'label'      => 'Phone',
				'input_type' => 'tel',
			],
		],
		'address' => [
			'type'        => 'object',
			'description' => 'Donor mailing address',
		],
		'user_id' => [
			'type'        => 'integer',
			'description' => 'Associated WordPress user ID',
			'form'        => [
				'label'      => 'Linked User',
				'input_type' => 'user_select',
			],
		],
		'lifetime_giving' => [
			'type'        => 'number',
			'default'     => 0,
			'description' => 'Total lifetime donations',
			'form'        => [
				'label'      => 'Lifetime Giving',
				'input_type' => 'currency_display',
				'readonly'   => true,
			],
		],
		'first_donation_date' => [
			'type'        => 'string',
			'format'      => 'date',
			'description' => 'Date of first donation',
			'form'        => [
				'label'      => 'First Donation',
				'input_type' => 'date_display',
				'readonly'   => true,
			],
		],
		'communication_preferences' => [
			'type'        => 'object',
			'description' => 'Email and communication preferences',
		],
	],
	'computed' => [
		'full_name' => [
			'function' => 'concat_names',
			'args'     => [ 'first_name', 'last_name' ],
		],
		'lifetime_giving_formatted' => [
			'function' => 'format_currency',
			'args'     => [ 'lifetime_giving' ],
		],
		'donor_level' => [
			'function' => 'calculate_donor_level',
			'args'     => [ 'lifetime_giving' ],
		],
		'formatted_address' => [
			'function' => 'format_address',
			'args'     => [ 'address' ],
		],
	],

	'abilities' => [
		'shelter-donors/get-profile' => [
			'label'       => 'Get Donor Profile',
			'description' => 'Retrieves complete donor profile',
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
				'description' => 'Hydrated donor entity with computed fields',
			],
		],

		'shelter-donors/update-address' => [
			'label'       => 'Update Donor Address',
			'description' => 'Updates donor mailing address',
			'permission'  => 'owner_or_admin',
			'meta'        => [
				'annotations'  => [ 'readonly' => false, 'destructive' => false, 'idempotent' => true ],
				'show_in_rest' => true,
			],
			'input' => [
				'required'   => [ 'donor_id', 'address' ],
				'properties' => [
					'donor_id' => [ 'type' => 'integer' ],
					// Resolved by Config::resolve_refs after manifest merge.
					'address'  => [ '$ref' => 'schemas/address.json' ],
					'source' => [
						'type'    => 'string',
						'enum'    => [ 'checkout', 'myaccount', 'order_sync', 'admin' ],
						'default' => 'myaccount',
					],
				],
			],
			'output' => [
				'properties' => [
					'success'  => [ 'type' => 'boolean' ],
					'donor_id' => [ 'type' => 'integer' ],
				],
			],
		],

		'shelter-donors/get-history' => [
			'label'       => 'Get Donor History',
			'description' => "Retrieves donor's complete giving history",
			'permission'  => 'owner_or_admin',
			'meta'        => [
				'annotations'  => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
				'show_in_rest' => true,
			],
			'input' => [
				'required'   => [ 'donor_id' ],
				'properties' => [
					'donor_id'           => [ 'type' => 'integer' ],
					'include_donations'  => [ 'type' => 'boolean', 'default' => true ],
					'include_memberships' => [ 'type' => 'boolean', 'default' => true ],
					'include_memorials'  => [ 'type' => 'boolean', 'default' => true ],
				],
			],
			'output' => [
				'properties' => [
					'donations'          => [ 'type' => 'array' ],
					'memberships'        => [ 'type' => 'array' ],
					'memorials'          => [ 'type' => 'array' ],
					'lifetime_giving'    => [ 'type' => 'number' ],
					'current_membership' => [ 'type' => 'object' ],
				],
			],
		],

		'shelter-donors/update-profile' => [
			'label'       => 'Update Donor Profile',
			'description' => 'Updates donor profile information (name, phone, preferences)',
			'permission'  => 'owner_or_admin',
			'meta'        => [
				'annotations'  => [ 'readonly' => false, 'destructive' => false, 'idempotent' => true ],
				'show_in_rest' => true,
			],
			'input' => [
				'required'   => [ 'donor_id' ],
				'properties' => [
					'donor_id'   => [ 'type' => 'integer', 'description' => 'Donor post ID' ],
					'first_name' => [ 'type' => 'string', 'maxLength' => 100 ],
					'last_name'  => [ 'type' => 'string', 'maxLength' => 100 ],
					// Note: maxLength here is 20, not the entity's 30. Audit-tracked drift.
					'phone'      => [ 'type' => 'string', 'maxLength' => 20 ],
					'communication_preferences' => [
						'type'        => 'object',
						'description' => 'Communication preferences. Persisted as meta key `_sd_communication_preferences`.',
						'properties'  => [
							'newsletter'         => [ 'type' => 'boolean' ],
							'donation_receipts'  => [ 'type' => 'boolean' ],
							'renewal_reminders'  => [ 'type' => 'boolean' ],
							'event_invitations'  => [ 'type' => 'boolean' ],
						],
					],
					'is_anonymous_default' => [
						'type'        => 'boolean',
						'description' => 'Default anonymity preference for future donations',
					],
				],
			],
			'output' => [
				'properties' => [
					'success'        => [ 'type' => 'boolean' ],
					'donor_id'       => [ 'type' => 'integer' ],
					'updated_fields' => [
						'type'  => 'array',
						'items' => [ 'type' => 'string' ],
					],
				],
			],
		],
	],

	'meta_boxes' => [
		'contact_info' => [
			'title'    => 'Contact Information',
			'context'  => 'normal',
			'priority' => 'high',
			'fields'   => [
				'first_name',
				'last_name',
				'email',
				'phone',
			],
		],
		'address' => [
			'title'   => 'Address',
			'context' => 'normal',
			// Self-contained sub-field entries — the form edits flat
			// `_sd_address_line_1`/etc. meta keys. The `composite_save`
			// directive below tells Meta_Boxes::save_meta_boxes to also
			// assemble those flat values into the canonical `_sd_address`
			// object that the abilities pipeline and REST consumers
			// read from. Closes the audit-flagged drift where admin
			// address edits didn't propagate to the structured store.
			'fields'  => [
				'address_line_1' => [ 'label' => 'Address Line 1', 'input_type' => 'text' ],
				'address_line_2' => [ 'label' => 'Address Line 2', 'input_type' => 'text' ],
				'city'           => [ 'label' => 'City',           'input_type' => 'text' ],
				'state'          => [ 'label' => 'State',          'input_type' => 'text' ],
				'postal_code'    => [ 'label' => 'Postal Code',    'input_type' => 'text' ],
			],
			'composite_save' => [
				'meta_key'  => '_sd_address',
				// Map each flat field_id to the object key the
				// abilities-pipeline writer uses (Abilities\Donors\update_address
				// produces line_1/line_2/postal_code keys).
				'field_map' => [
					'address_line_1' => 'line_1',
					'address_line_2' => 'line_2',
					'city'           => 'city',
					'state'          => 'state',
					'postal_code'    => 'postal_code',
				],
			],
		],
		'donor_stats' => [
			'title'   => 'Donor Statistics',
			'context' => 'side',
			'fields'  => [
				// lifetime_giving and first_donation_date are real entity
				// fields; their form sub-blocks declare the display-only
				// rendering used here.
				'lifetime_giving',
				// Self-contained: donation_count isn't an entity field
				// (stored as `_sd_donation_count` meta only); donor_level
				// is a computed-field-derived display.
				'donation_count' => [ 'label' => 'Total Donations', 'input_type' => 'number_display', 'readonly' => true ],
				'donor_level'    => [ 'label' => 'Donor Level',     'input_type' => 'level_badge',    'readonly' => true ],
				'first_donation_date',
			],
		],
		'user_account' => [
			'title'    => 'User Account',
			'context'  => 'side',
			'priority' => 'low',
			'fields'   => [ 'user_id' ],
		],
	],

	'emails' => [
		'donor-annual-summary' => [
			'title'         => 'Annual Giving Summary',
			'description'   => 'Annual summary of donations for tax purposes',
			'trigger_hook'  => 'starter_shelter_annual_summary',
			'trigger_args'  => [ 'donor_id', 'year', 'summary' ],
			'entities'      => [
				'donor' => [ 'entity' => 'sd_donor', 'id_from' => 'donor_id' ],
			],
			'recipient_type' => 'donor',
			'subject'        => 'Your {year} Giving Summary from {site_name}',
			'heading'        => 'Annual Giving Summary',
			'template'       => 'emails/donor-annual-summary.php',
			'placeholders'   => [
				'donor_name'      => 'donor.full_name',
				'year'            => 'args.year',
				'total_donations' => 'args.summary.total_formatted',
				'donation_count'  => 'args.summary.count',
			],
		],
	],
];
