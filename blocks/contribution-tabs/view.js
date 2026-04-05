/**
 * Contribution Tabs Block - Frontend Interactivity
 *
 * @package Starter_Shelter
 * @since 2.1.0
 */

import { store, getContext } from '@wordpress/interactivity';

const NAMESPACE = 'starter-shelter/contribution-tabs';

const { state } = store( NAMESPACE, {
	state: {
		tabs: {},
	},

	actions: {
		selectTab() {
			const ctx = getContext();
			const tab = state.tabs[ ctx.blockId ];
			if ( tab ) {
				tab.activeTab = ctx.tabIndex;
			}
		},
	},

	callbacks: {
		isActiveTab() {
			const ctx = getContext();
			const tab = state.tabs[ ctx.blockId ];
			return tab?.activeTab === ctx.tabIndex;
		},
	},
} );
