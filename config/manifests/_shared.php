<?php
/**
 * Non-entity manifest: shared fields used across multiple entity types.
 *
 * The leading underscore in the filename signals "not a CPT-scoped
 * manifest" — `Field_Manifest::list_entities()` filters these out so
 * the merged entities.json doesn't accidentally gain a "_shared" entry.
 * `Field_Manifest::list_all_manifests()` includes them; share-aware
 * accessors (`get_checkout_fields`, etc.) iterate the wider list.
 *
 * What belongs here: fields and checkout/admin/email surface declarations
 * that aren't owned by any single entity. Today: just the `is_anonymous`
 * checkout flag, used across all four product types (donation,
 * membership, business_membership, memorial). Future candidates could
 * include audit timestamps (created_at, updated_at) or other genuinely
 * cross-cutting concepts.
 *
 * What does NOT belong here: cross-cutting AGGREGATIONS (the
 * `shelter-reports/*` abilities aggregate over multiple entities but
 * have their own home — they live in abilities.json and aren't
 * manifest-owned at all right now).
 *
 * @package Starter_Shelter
 * @subpackage Manifests
 * @since 1.1.2
 *
 * @see Starter_Shelter\Core\Field_Manifest
 */

declare( strict_types = 1 );

return [
	'meta_prefix' => '_sd_',
	'fields'      => [
		'is_anonymous' => [
			'type'        => 'boolean',
			'default'     => false,
			'description' => 'Whether the donation, membership, or memorial is publicly attributed.',
			'form'        => [
				'label'       => 'Make my donation anonymous',
				'input_type'  => 'checkbox',
				'description' => 'Your name will not be publicly listed.',
			],
		],
	],
	'checkout_fields' => [
		'is_anonymous' => [
			'required' => false,
			'priority' => 10,
			'class'    => [ 'form-row-wide' ],
			// No `product_types`: applies to all checkout flows.
		],
	],
];
