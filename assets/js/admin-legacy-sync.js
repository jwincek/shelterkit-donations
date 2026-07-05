/**
 * Legacy Order Sync Admin UI
 *
 * React-based interface for syncing legacy WooCommerce orders to the
 * Shelter Donations system.
 *
 * @package Starter_Shelter
 * @since 1.0.0
 */

( function() {
    'use strict';

    const { createElement: el, useState, useEffect, useCallback, Fragment } = wp.element;
    const { 
        Button, 
        Card, 
        CardBody, 
        CardHeader, 
        CheckboxControl,
        Flex,
        FlexItem,
        Icon,
        Modal,
        Notice,
        Panel,
        PanelBody,
        SelectControl,
        Spinner,
        TextControl,
        ToggleControl,
    } = wp.components;
    const { __, sprintf } = wp.i18n;

    // Get localized data
    const config = window.sdLegacySync || {};

    /**
     * Stats Card Component
     */
    function StatCard( { value, label, type } ) {
        return el( 'div', { 
            className: `sd-stat-card sd-stat-${ type }` 
        },
            el( 'div', { className: 'sd-stat-value' }, value.toLocaleString() ),
            el( 'div', { className: 'sd-stat-label' }, label )
        );
    }

    /**
     * Stats Grid Component
     */
    function StatsGrid( { stats } ) {
        return el( 'div', { className: 'sd-stats-grid' },
            el( StatCard, { 
                value: stats.total_orders || 0, 
                label: __( 'Total Orders', 'shelter-donations' ),
                type: 'total'
            }),
            el( StatCard, { 
                value: stats.unsynced_orders || 0, 
                label: __( 'Unsynced', 'shelter-donations' ),
                type: 'unsynced'
            }),
            el( StatCard, { 
                value: stats.synced_orders || 0, 
                label: __( 'Legacy Synced', 'shelter-donations' ),
                type: 'synced'
            }),
            el( StatCard, { 
                value: stats.processed_orders || 0, 
                label: __( 'Auto Processed', 'shelter-donations' ),
                type: 'processed'
            })
        );
    }

    /**
     * Filters Component
     */
    function Filters( { filters, onChange, onScan, isScanning } ) {
        return el( 'div', { className: 'sd-filters' },
            el( 'div', { className: 'sd-filter-group' },
                el( 'label', null, __( 'Order Status', 'shelter-donations' ) ),
                el( SelectControl, {
                    value: filters.status,
                    options: [
                        { label: __( 'All', 'shelter-donations' ), value: 'all' },
                        { label: __( 'Completed', 'shelter-donations' ), value: 'completed' },
                        { label: __( 'Processing', 'shelter-donations' ), value: 'processing' },
                    ],
                    onChange: ( value ) => onChange( { ...filters, status: value } ),
                    __nextHasNoMarginBottom: true,
                })
            ),
            el( 'div', { className: 'sd-filter-group' },
                el( 'label', null, __( 'Product Type', 'shelter-donations' ) ),
                el( SelectControl, {
                    value: filters.product_type,
                    options: [
                        { label: __( 'All Types', 'shelter-donations' ), value: 'all' },
                        { label: __( 'Donations', 'shelter-donations' ), value: 'donation' },
                        { label: __( 'Memberships', 'shelter-donations' ), value: 'membership' },
                        { label: __( 'Memorials', 'shelter-donations' ), value: 'memorial' },
                    ],
                    onChange: ( value ) => onChange( { ...filters, product_type: value } ),
                    __nextHasNoMarginBottom: true,
                })
            ),
            el( 'div', { className: 'sd-filter-group' },
                el( 'label', null, __( 'Date From', 'shelter-donations' ) ),
                el( TextControl, {
                    type: 'date',
                    value: filters.date_from,
                    onChange: ( value ) => onChange( { ...filters, date_from: value } ),
                    __nextHasNoMarginBottom: true,
                })
            ),
            el( 'div', { className: 'sd-filter-group' },
                el( 'label', null, __( 'Date To', 'shelter-donations' ) ),
                el( TextControl, {
                    type: 'date',
                    value: filters.date_to,
                    onChange: ( value ) => onChange( { ...filters, date_to: value } ),
                    __nextHasNoMarginBottom: true,
                })
            ),
            el( 'div', { className: 'sd-filter-group', style: { alignSelf: 'flex-end' } },
                el( CheckboxControl, {
                    label: __( 'Include already synced', 'shelter-donations' ),
                    checked: filters.include_synced,
                    onChange: ( value ) => onChange( { ...filters, include_synced: value } ),
                    __nextHasNoMarginBottom: true,
                })
            ),
            el( 'div', { className: 'sd-filter-group', style: { alignSelf: 'flex-end' } },
                el( Button, {
                    variant: 'primary',
                    onClick: onScan,
                    isBusy: isScanning,
                    disabled: isScanning,
                },
                    isScanning ? __( 'Scanning...', 'shelter-donations' ) : __( 'Scan Orders', 'shelter-donations' )
                )
            )
        );
    }

    /**
     * Product Type Badge Component
     */
    function ProductTypeBadge( { type } ) {
        return el( 'span', { 
            className: `sd-product-type-badge ${ type }` 
        }, type );
    }

    /**
     * Sync Status Badge Component
     */
    function SyncStatusBadge( { status } ) {
        const labels = {
            unsynced: __( 'Unsynced', 'shelter-donations' ),
            synced: __( 'Synced', 'shelter-donations' ),
            processed: __( 'Processed', 'shelter-donations' ),
        };
        
        return el( 'span', { 
            className: `sd-sync-status ${ status }` 
        }, labels[ status ] || status );
    }

    /**
     * Orders Table Component
     */
    function OrdersTable( { orders, selectedOrders, onSelectOrder, onSelectAll, onPreview, forceResync } ) {
        const selectableOrders = forceResync 
            ? orders 
            : orders.filter( o => o.sync_status === 'unsynced' );
        const allSelected = selectableOrders.length > 0 && selectableOrders.every( o => selectedOrders.includes( o.order_id ) );
        const someSelected = selectedOrders.length > 0 && ! allSelected;

        return el( 'table', { className: 'sd-orders-table' },
            el( 'thead', null,
                el( 'tr', null,
                    el( 'th', { style: { width: '40px' } },
                        el( CheckboxControl, {
                            checked: allSelected,
                            indeterminate: someSelected,
                            onChange: () => onSelectAll( allSelected ? [] : selectableOrders.map( o => o.order_id ) ),
                            __nextHasNoMarginBottom: true,
                        })
                    ),
                    el( 'th', null, __( 'Order', 'shelter-donations' ) ),
                    el( 'th', null, __( 'Date', 'shelter-donations' ) ),
                    el( 'th', null, __( 'Customer', 'shelter-donations' ) ),
                    el( 'th', null, __( 'Items', 'shelter-donations' ) ),
                    el( 'th', null, __( 'Total', 'shelter-donations' ) ),
                    el( 'th', null, __( 'Status', 'shelter-donations' ) ),
                    el( 'th', null, __( 'Actions', 'shelter-donations' ) )
                )
            ),
            el( 'tbody', null,
                orders.map( order => {
                    const canSelect = forceResync || order.sync_status === 'unsynced';
                    return el( 'tr', { 
                        key: order.order_id,
                        className: order.sync_status !== 'unsynced' ? 'sd-order-synced' : ''
                    },
                        el( 'td', null,
                            el( CheckboxControl, {
                                checked: selectedOrders.includes( order.order_id ),
                                onChange: () => onSelectOrder( order.order_id ),
                                disabled: ! canSelect,
                                __nextHasNoMarginBottom: true,
                            })
                        ),
                        el( 'td', null,
                            el( 'a', { 
                                href: order.edit_url, 
                                target: '_blank',
                                rel: 'noopener noreferrer'
                            }, `#${ order.order_number }` )
                        ),
                        el( 'td', null, order.date_formatted ),
                        el( 'td', null,
                            el( 'div', null, order.customer_name ),
                            el( 'small', { style: { color: '#646970' } }, order.customer_email )
                        ),
                        el( 'td', null,
                            order.items.map( ( item, idx ) =>
                                el( 'div', { key: idx, style: { marginBottom: '4px' } },
                                    el( ProductTypeBadge, { type: item.product_type } ),
                                    ' ',
                                    el( 'span', { style: { fontSize: '12px' } }, item.name )
                                )
                            )
                        ),
                        el( 'td', null, order.total_formatted ),
                        el( 'td', null, el( SyncStatusBadge, { status: order.sync_status } ) ),
                        el( 'td', null,
                            el( Button, {
                                variant: 'link',
                                onClick: () => onPreview( order.order_id ),
                                isSmall: true,
                            }, __( 'Preview', 'shelter-donations' ) )
                        )
                    );
                })
            )
        );
    }

    /**
     * Preview Modal Component
     */
    function PreviewModal( { orderId, onClose } ) {
        const [ preview, setPreview ] = useState( null );
        const [ isLoading, setIsLoading ] = useState( true );
        const [ error, setError ] = useState( null );

        useEffect( () => {
            setIsLoading( true );
            setError( null );

            const formData = new FormData();
            formData.append( 'action', 'sd_preview_legacy_order' );
            formData.append( 'nonce', config.nonce );
            formData.append( 'order_id', orderId );

            fetch( config.ajaxUrl, {
                method: 'POST',
                body: formData,
            })
            .then( response => response.json() )
            .then( data => {
                if ( data.success ) {
                    setPreview( data.data );
                } else {
                    setError( data.data || __( 'Failed to load preview.', 'shelter-donations' ) );
                }
            })
            .catch( () => {
                setError( __( 'Failed to load preview.', 'shelter-donations' ) );
            })
            .finally( () => {
                setIsLoading( false );
            });
        }, [ orderId ] );

        return el( Modal, {
            title: __( 'Sync Preview', 'shelter-donations' ) + ` - Order #${ orderId }`,
            onRequestClose: onClose,
        },
            isLoading && el( 'div', { style: { textAlign: 'center', padding: '40px' } },
                el( Spinner ),
                el( 'p', null, __( 'Loading preview...', 'shelter-donations' ) )
            ),
            error && el( Notice, { status: 'error', isDismissible: false }, error ),
            preview && el( Fragment, null,
                el( 'h4', null, __( 'Donor', 'shelter-donations' ) ),
                el( 'p', null,
                    preview.donor.name,
                    el( 'br' ),
                    el( 'small', null, preview.donor.email ),
                    preview.donor.existing && el( Fragment, null,
                        el( 'br' ),
                        el( 'span', { 
                            style: { 
                                color: '#00a32a', 
                                fontSize: '12px' 
                            } 
                        }, 
                            __( '✓ Existing donor record', 'shelter-donations' )
                        )
                    )
                ),
                el( 'h4', null, __( 'Records to Create', 'shelter-donations' ) ),
                preview.items.map( ( item, idx ) =>
                    el( Card, { key: idx, size: 'small', style: { marginBottom: '12px' } },
                        el( CardBody, null,
                            el( 'strong', null, item.name ),
                            el( 'p', { style: { margin: '8px 0 0' } }, item.will_create )
                        )
                    )
                )
            )
        );
    }

    /**
     * Progress Bar Component
     */
    function ProgressBar( { progress, total } ) {
        const percent = total > 0 ? Math.round( ( progress / total ) * 100 ) : 0;
        
        return el( 'div', { className: 'sd-progress-bar' },
            el( 'div', { 
                className: 'sd-progress-bar-fill',
                style: { width: `${ percent }%` }
            })
        );
    }

    /**
     * Sync Results Component
     */
    function SyncResults( { results } ) {
        return el( Card, null,
            el( CardHeader, null, __( 'Sync Results', 'shelter-donations' ) ),
            el( CardBody, null,
                el( 'div', { className: 'sd-stats-grid' },
                    el( StatCard, { 
                        value: results.processed, 
                        label: __( 'Processed', 'shelter-donations' ),
                        type: 'synced'
                    }),
                    el( StatCard, { 
                        value: results.created.donations, 
                        label: __( 'Donations', 'shelter-donations' ),
                        type: 'total'
                    }),
                    el( StatCard, { 
                        value: results.created.memberships, 
                        label: __( 'Memberships', 'shelter-donations' ),
                        type: 'total'
                    }),
                    el( StatCard, { 
                        value: results.created.memorials, 
                        label: __( 'Memorials', 'shelter-donations' ),
                        type: 'total'
                    }),
                    ( results.created.updated > 0 ) && el( StatCard, { 
                        value: results.created.updated, 
                        label: __( 'Updated', 'shelter-donations' ),
                        type: 'processed'
                    })
                ),
                results.skipped > 0 && el( Notice, { 
                    status: 'warning', 
                    isDismissible: false 
                }, 
                    sprintf(
                        // translators: %d: number of orders skipped because they were already synced.
                        __( '%d order(s) skipped (already synced).', 'shelter-donations' ),
                        results.skipped
                    )
                ),
                results.errors > 0 && el( Notice, { 
                    status: 'error', 
                    isDismissible: false 
                },
                    sprintf(
                        // translators: %d: number of errors that occurred during sync.
                        __( '%d error(s) occurred during sync.', 'shelter-donations' ),
                        results.errors
                    )
                )
            )
        );
    }

    /**
     * Main App Component
     */
    function LegacySyncApp() {
        const [ stats, setStats ] = useState( config.stats || {} );
        const [ orders, setOrders ] = useState( [] );
        const [ selectedOrders, setSelectedOrders ] = useState( [] );
        const [ isScanning, setIsScanning ] = useState( false );
        const [ isSyncing, setIsSyncing ] = useState( false );
        const [ syncProgress, setSyncProgress ] = useState( 0 );
        const [ syncResults, setSyncResults ] = useState( null );
        const [ previewOrderId, setPreviewOrderId ] = useState( null );
        const [ notice, setNotice ] = useState( null );
        const [ forceResync, setForceResync ] = useState( false );
        const [ filters, setFilters ] = useState({
            status: 'all',
            product_type: 'all',
            date_from: '',
            date_to: '',
            include_synced: false,
        });
        const [ pagination, setPagination ] = useState({
            page: 1,
            per_page: 50,
            total: 0,
            total_pages: 0,
        });

        // Refresh stats
        const refreshStats = useCallback( () => {
            const formData = new FormData();
            formData.append( 'action', 'sd_get_sync_stats' );
            formData.append( 'nonce', config.nonce );

            fetch( config.ajaxUrl, {
                method: 'POST',
                body: formData,
            })
            .then( response => response.json() )
            .then( data => {
                if ( data.success ) {
                    setStats( data.data );
                }
            });
        }, [] );

        // Scan orders
        const handleScan = useCallback( () => {
            setIsScanning( true );
            setOrders( [] );
            setSelectedOrders( [] );
            setSyncResults( null );

            const formData = new FormData();
            formData.append( 'action', 'sd_scan_legacy_orders' );
            formData.append( 'nonce', config.nonce );
            formData.append( 'page', pagination.page );
            formData.append( 'per_page', pagination.per_page );
            
            Object.entries( filters ).forEach( ( [ key, value ] ) => {
                formData.append( key, value );
            });

            fetch( config.ajaxUrl, {
                method: 'POST',
                body: formData,
            })
            .then( response => response.json() )
            .then( data => {
                if ( data.success ) {
                    setOrders( data.data.orders );
                    setPagination( data.data.pagination );
                    setNotice({
                        status: 'success',
                        message: sprintf(
                            // translators: %d: number of orders found containing shelter products.
                            __( 'Found %d order(s) with shelter products.', 'shelter-donations' ),
                            data.data.summary.total
                        ),
                    });
                } else {
                    setNotice({
                        status: 'error',
                        message: data.data || __( 'Scan failed.', 'shelter-donations' ),
                    });
                }
            })
            .catch( () => {
                setNotice({
                    status: 'error',
                    message: __( 'Scan failed.', 'shelter-donations' ),
                });
            })
            .finally( () => {
                setIsScanning( false );
            });
        }, [ filters, pagination.page, pagination.per_page ] );

        // Toggle order selection
        const handleSelectOrder = useCallback( ( orderId ) => {
            setSelectedOrders( prev => {
                if ( prev.includes( orderId ) ) {
                    return prev.filter( id => id !== orderId );
                }
                return [ ...prev, orderId ];
            });
        }, [] );

        // Sync selected orders
        const handleSync = useCallback( () => {
            if ( selectedOrders.length === 0 ) {
                setNotice({
                    status: 'warning',
                    message: __( 'Please select orders to sync.', 'shelter-donations' ),
                });
                return;
            }

            const confirmMessage = forceResync 
                ? __( 'Are you sure you want to re-sync these orders? This may create DUPLICATE records if the orders were previously synced.', 'shelter-donations' )
                : config.strings.confirmSync;

            if ( ! confirm( confirmMessage ) ) {
                return;
            }

            setIsSyncing( true );
            setSyncProgress( 0 );
            setSyncResults( null );

            const formData = new FormData();
            formData.append( 'action', 'sd_sync_legacy_orders' );
            formData.append( 'nonce', config.nonce );
            formData.append( 'skip_errors', '1' );
            if ( forceResync ) {
                formData.append( 'force_resync', '1' );
            }
            selectedOrders.forEach( id => {
                formData.append( 'order_ids[]', id );
            });

            fetch( config.ajaxUrl, {
                method: 'POST',
                body: formData,
            })
            .then( response => response.json() )
            .then( data => {
                if ( data.success ) {
                    setSyncResults( data.data );
                    setSelectedOrders( [] );
                    refreshStats();
                    
                    // Re-scan to update order statuses
                    handleScan();

                    setNotice({
                        status: 'success',
                        message: sprintf(
                            // translators: %d: number of orders successfully synced.
                            __( 'Synced %d order(s) successfully.', 'shelter-donations' ),
                            data.data.processed
                        ),
                    });
                } else {
                    setNotice({
                        status: 'error',
                        message: data.data || __( 'Sync failed.', 'shelter-donations' ),
                    });
                }
            })
            .catch( () => {
                setNotice({
                    status: 'error',
                    message: __( 'Sync failed.', 'shelter-donations' ),
                });
            })
            .finally( () => {
                setIsSyncing( false );
            });
        }, [ selectedOrders, forceResync, refreshStats, handleScan ] );

        // Sync all matching orders with batching
        const handleSyncAll = useCallback( () => {
            const confirmMessage = forceResync 
                ? __( 'Are you sure you want to re-sync ALL matching orders? This may create DUPLICATE records.', 'shelter-donations' )
                : __( 'Are you sure you want to sync ALL matching orders? This may take a while for large datasets.', 'shelter-donations' );

            if ( ! confirm( confirmMessage ) ) {
                return;
            }

            setIsSyncing( true );
            setSyncProgress( 0 );
            setSyncResults( null );

            // Track cumulative results across batches.
            let cumulativeResults = {
                processed: 0,
                skipped: 0,
                errors: 0,
                created: {
                    donations: 0,
                    memberships: 0,
                    memorials: 0,
                    donors: 0,
                    updated: 0,
                },
            };

            const syncBatch = ( offset = 0 ) => {
                const formData = new FormData();
                formData.append( 'action', 'sd_sync_all_legacy_orders' );
                formData.append( 'nonce', config.nonce );
                formData.append( 'offset', offset );
                formData.append( 'batch_size', 50 );
                
                // Pass current filters.
                Object.entries( filters ).forEach( ( [ key, value ] ) => {
                    formData.append( key, value );
                });
                
                if ( forceResync ) {
                    formData.append( 'force_resync', '1' );
                }

                fetch( config.ajaxUrl, {
                    method: 'POST',
                    body: formData,
                })
                .then( response => response.json() )
                .then( data => {
                    if ( data.success ) {
                        // Accumulate results.
                        cumulativeResults.processed += data.data.processed || 0;
                        cumulativeResults.skipped += data.data.skipped || 0;
                        cumulativeResults.errors += data.data.errors || 0;
                        cumulativeResults.created.donations += data.data.created?.donations || 0;
                        cumulativeResults.created.memberships += data.data.created?.memberships || 0;
                        cumulativeResults.created.memorials += data.data.created?.memorials || 0;
                        cumulativeResults.created.donors += data.data.created?.donors || 0;
                        cumulativeResults.created.updated += data.data.created?.updated || 0;

                        setSyncProgress( data.data.progress || 0 );

                        if ( data.data.complete ) {
                            // Done!
                            setSyncResults( cumulativeResults );
                            setIsSyncing( false );
                            refreshStats();
                            handleScan();
                            
                            setNotice({
                                status: 'success',
                                message: sprintf(
                                    // translators: %d: total number of orders processed.
                                    __( 'Sync complete! Processed %d order(s).', 'shelter-donations' ),
                                    cumulativeResults.processed
                                ),
                            });
                        } else {
                            // Process next batch.
                            syncBatch( data.data.offset );
                        }
                    } else {
                        setIsSyncing( false );
                        setNotice({
                            status: 'error',
                            message: data.data || __( 'Sync failed.', 'shelter-donations' ),
                        });
                    }
                })
                .catch( () => {
                    setIsSyncing( false );
                    setNotice({
                        status: 'error',
                        message: __( 'Sync failed.', 'shelter-donations' ),
                    });
                });
            };

            // Start with offset 0.
            syncBatch( 0 );
        }, [ filters, forceResync, refreshStats, handleScan ] );

        // Reset sync status
        const handleReset = useCallback( () => {
            if ( ! confirm( config.strings.confirmReset ) ) {
                return;
            }

            const formData = new FormData();
            formData.append( 'action', 'sd_reset_sync_status' );
            formData.append( 'nonce', config.nonce );
            formData.append( 'reset_all', '1' );

            fetch( config.ajaxUrl, {
                method: 'POST',
                body: formData,
            })
            .then( response => response.json() )
            .then( data => {
                if ( data.success ) {
                    refreshStats();
                    setOrders( [] );
                    setNotice({
                        status: 'success',
                        message: data.data.message,
                    });
                } else {
                    setNotice({
                        status: 'error',
                        message: data.data || __( 'Reset failed.', 'shelter-donations' ),
                    });
                }
            });
        }, [ refreshStats ] );

        return el( 'div', { className: 'sd-legacy-sync-app' },
            // Notices
            notice && el( Notice, {
                status: notice.status,
                isDismissible: true,
                onRemove: () => setNotice( null ),
            }, notice.message ),

            // Stats Card
            el( Card, { style: { marginBottom: '20px' } },
                el( CardHeader, null,
                    el( Flex, { justify: 'space-between', align: 'center' },
                        el( FlexItem, null, __( 'Overview', 'shelter-donations' ) ),
                        el( FlexItem, null,
                            el( Button, {
                                variant: 'link',
                                onClick: refreshStats,
                                isSmall: true,
                            }, __( 'Refresh', 'shelter-donations' ) )
                        )
                    )
                ),
                el( CardBody, null,
                    el( StatsGrid, { stats } ),
                    stats.last_scan && el( 'p', { 
                        style: { 
                            margin: 0, 
                            fontSize: '12px', 
                            color: '#646970' 
                        } 
                    }, 
                        // translators: %s: date/time of the last scan.
                        sprintf( __( 'Last scan: %s', 'shelter-donations' ), stats.last_scan )
                    )
                )
            ),

            // Filters & Scan Card
            el( Card, { style: { marginBottom: '20px' } },
                el( CardHeader, null, __( 'Scan Orders', 'shelter-donations' ) ),
                el( CardBody, null,
                    el( Filters, {
                        filters,
                        onChange: setFilters,
                        onScan: handleScan,
                        isScanning,
                    })
                )
            ),

            // Syncing Progress
            isSyncing && el( Card, { style: { marginBottom: '20px' } },
                el( CardBody, null,
                    el( 'div', { style: { textAlign: 'center' } },
                        el( Spinner ),
                        el( 'p', null, config.strings.syncing )
                    )
                )
            ),

            // Sync Results
            syncResults && el( 'div', { style: { marginBottom: '20px' } },
                el( SyncResults, { results: syncResults } )
            ),

            // Orders Table
            orders.length > 0 && el( Card, null,
                el( CardHeader, null,
                    el( Flex, { justify: 'space-between', align: 'center' },
                        el( FlexItem, null,
                            sprintf(
                                // translators: %d: number of orders found.
                                __( '%d Order(s) Found (showing first 50)', 'shelter-donations' ),
                                orders.length
                            )
                        ),
                        el( FlexItem, null,
                            el( Flex, { gap: 3, align: 'center' },
                                el( CheckboxControl, {
                                    label: __( 'Force re-sync (creates duplicates!)', 'shelter-donations' ),
                                    checked: forceResync,
                                    onChange: ( value ) => {
                                        setForceResync( value );
                                        setSelectedOrders( [] ); // Clear selection when toggling
                                    },
                                    __nextHasNoMarginBottom: true,
                                }),
                                el( Button, {
                                    variant: 'primary',
                                    onClick: handleSyncAll,
                                    disabled: isSyncing,
                                    isBusy: isSyncing,
                                },
                                    forceResync
                                        ? __( 'Re-Sync All Matching', 'shelter-donations' )
                                        : __( 'Sync All Matching', 'shelter-donations' )
                                ),
                                el( Button, {
                                    variant: 'secondary',
                                    onClick: handleSync,
                                    disabled: selectedOrders.length === 0 || isSyncing,
                                    isBusy: isSyncing,
                                },
                                    // translators: %d: number of selected orders.
                                    sprintf( __( 'Sync Selected (%d)', 'shelter-donations' ), selectedOrders.length )
                                ),
                                el( Button, {
                                    variant: 'tertiary',
                                    onClick: handleReset,
                                    disabled: isSyncing,
                                    isDestructive: true,
                                }, __( 'Reset All', 'shelter-donations' ) )
                            )
                        )
                    )
                ),
                // Progress bar when syncing
                isSyncing && syncProgress > 0 && el( 'div', { 
                    style: { 
                        margin: '0 16px 16px', 
                        background: '#f0f0f0', 
                        borderRadius: '4px',
                        overflow: 'hidden'
                    } 
                },
                    el( 'div', { 
                        style: { 
                            width: `${syncProgress}%`, 
                            height: '8px', 
                            background: '#007cba',
                            transition: 'width 0.3s ease'
                        } 
                    }),
                    el( 'div', { 
                        style: { 
                            textAlign: 'center', 
                            fontSize: '12px', 
                            padding: '4px',
                            color: '#646970'
                        } 
                    },
                    // translators: %d: percent of the sync completed (0-100).
                    sprintf( __( '%d%% complete', 'shelter-donations' ), syncProgress ) )
                ),
                forceResync && el( Notice, {
                    status: 'warning',
                    isDismissible: false,
                    style: { margin: '0 16px' }
                }, __( 'Warning: Force re-sync is enabled. This will create new records even for already-synced orders, potentially creating duplicates. Use with caution!', 'shelter-donations' ) ),
                el( CardBody, null,
                    el( OrdersTable, {
                        orders,
                        selectedOrders,
                        onSelectOrder: handleSelectOrder,
                        onSelectAll: setSelectedOrders,
                        onPreview: setPreviewOrderId,
                        forceResync,
                    })
                )
            ),

            // No orders message
            ! isScanning && orders.length === 0 && el( Card, null,
                el( CardBody, null,
                    el( 'p', { style: { textAlign: 'center', margin: '40px 0' } },
                        __( 'Click "Scan Orders" to find orders with shelter products.', 'shelter-donations' )
                    )
                )
            ),

            // Preview Modal
            previewOrderId && el( PreviewModal, {
                orderId: previewOrderId,
                onClose: () => setPreviewOrderId( null ),
            })
        );
    }

    // Mount the app when DOM is ready
    document.addEventListener( 'DOMContentLoaded', function() {
        const container = document.getElementById( 'sd-legacy-sync-app' );
        if ( container ) {
            wp.element.render( el( LegacySyncApp ), container );
        }
    });

})();
