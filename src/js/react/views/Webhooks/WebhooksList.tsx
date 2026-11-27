/**
 * Webhooks list: DataViews table of outgoing update reports.
 */
import {
	DataViews,
	filterSortAndPaginate,
	type Field,
	type View,
} from '@wordpress/dataviews';
import { Button, Modal } from '@wordpress/components';
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Card, Stack } from '@wordpress/ui';
import sendCommand from '../../../utils/SendCommand';
import { DATAVIEWS_STORAGE_KEYS } from '../../../utils/dataviews-view-storage';
import { ErrorFallback } from '../../components/ErrorBoundary';
import Loader from '../../components/Loader';
import usePersistedDataView from '../../hooks/usePersistedDataView';
import { deliveryLabel, reportLabel } from './labels';
import type { WebhookListItem } from './types';
import { getWebhookActions } from './webhook-actions';
import WebhooksFooter, {
	type SnackbarState,
	type SnackbarType,
} from './WebhooksFooter';

type WebhooksListPayload = {
	webhooks: Array<
		Omit<
			WebhookListItem,
			'enabledLabel' | 'reportsLabel' | 'lastDeliveryLabel'
		>
	>;
};

const tableColumnStyles = {
	name: {
		width: '180px',
		maxWidth: '240px',
	},
	enabledLabel: {
		width: '120px',
		maxWidth: '140px',
	},
	url: {
		width: '280px',
		maxWidth: '420px',
	},
	reportsLabel: {
		width: '220px',
		maxWidth: '280px',
	},
	lastDeliveryLabel: {
		width: '180px',
		maxWidth: '240px',
	},
};

const defaultView: View = {
	type: 'table',
	search: '',
	page: 1,
	perPage: 20,
	titleField: 'name',
	fields: [ 'enabledLabel', 'url', 'reportsLabel', 'lastDeliveryLabel' ],
	sort: {
		field: 'name',
		direction: 'asc',
	},
	layout: {
		density: 'balanced',
		styles: tableColumnStyles,
	},
};

const webhookFieldIds = [ 'name', ...( defaultView.fields ?? [] ) ];

type WebhooksDataViewsProps = {
	searchLabel: string;
	snackbar: SnackbarState;
	onSnackbarClose: () => void;
};

const WebhooksDataViews = ( props: WebhooksDataViewsProps ) => {
	const { searchLabel, snackbar, onSnackbarClose } = props;

	return (
		<>
			<Stack
				direction="row"
				align="top"
				justify="space-between"
				className="dataviews__view-actions"
				gap="xs"
			>
				<Stack
					direction="row"
					justify="start"
					gap="sm"
					className="dataviews__search"
				>
					<DataViews.Search label={ searchLabel } />
				</Stack>
				<Stack direction="row" gap="xs" style={ { flexShrink: 0 } }>
					<DataViews.LayoutSwitcher />
					<DataViews.ViewConfig />
				</Stack>
			</Stack>
			<DataViews.Layout />
			<WebhooksFooter
				snackbar={ snackbar }
				onSnackbarClose={ onSnackbarClose }
			/>
		</>
	);
};

const decorateWebhook = (
	webhook: WebhooksListPayload[ 'webhooks' ][ number ]
): WebhookListItem => {
	return {
		...webhook,
		enabledLabel: webhook.enabled
			? __( 'Enabled', 'updatebee' )
			: __( 'Disabled', 'updatebee' ),
		reportsLabel: webhook.reports
			.map( ( report ) => reportLabel( report ) )
			.join( ', ' ),
		lastDeliveryLabel: deliveryLabel( webhook.lastDelivery ),
	};
};

/**
 * Outgoing webhook table.
 */
const WebhooksList = () => {
	const [ isLoading, setIsLoading ] = useState( true );
	const [ isError, setIsError ] = useState( false );
	const [ isBusy, setIsBusy ] = useState( false );
	const [ webhooks, setWebhooks ] = useState< WebhookListItem[] >( [] );
	const [ pendingDelete, setPendingDelete ] =
		useState< WebhookListItem | null >( null );
	const [ snackbar, setSnackbar ] = useState< SnackbarState >( {
		isVisible: false,
		message: '',
		type: 'success',
	} );
	const { view, onChangeView, onReset } = usePersistedDataView(
		DATAVIEWS_STORAGE_KEYS.webhooks,
		defaultView,
		webhookFieldIds
	);

	const notify = useCallback( ( message: string, type: SnackbarType ) => {
		setSnackbar( {
			isVisible: true,
			message,
			type,
		} );
	}, [] );

	const loadWebhooks = useCallback( () => {
		setIsLoading( true );
		sendCommand< WebhooksListPayload >( 'updatebee_get_webhooks', {
			nonce: updatebeeWebhooks.listNonce,
		} )
			.then( ( response ) => {
				const rows = response.data.data.webhooks ?? [];
				setWebhooks( rows.map( ( row ) => decorateWebhook( row ) ) );
				setIsError( false );
			} )
			.catch( () => {
				setIsError( true );
			} )
			.finally( () => {
				setIsLoading( false );
			} );
	}, [] );

	useEffect( () => {
		loadWebhooks();
	}, [ loadWebhooks ] );

	const toggleWebhook = useCallback(
		( item: WebhookListItem, enabled: boolean ) => {
			setIsBusy( true );
			sendCommand< {
				message: string;
				webhook: WebhooksListPayload[ 'webhooks' ][ number ];
			} >( 'updatebee_set_webhook_enabled', {
				nonce: item.enableNonce,
				id: item.id,
				enabled,
			} )
				.then( ( response ) => {
					const next = decorateWebhook( response.data.data.webhook );
					setWebhooks( ( current ) =>
						current.map( ( webhook ) =>
							webhook.id === next.id ? next : webhook
						)
					);
					notify( response.data.data.message, 'success' );
				} )
				.catch( ( error: Error ) => {
					notify(
						error.message ||
							__(
								'Webhook status could not be changed.',
								'updatebee'
							),
						'error'
					);
				} )
				.finally( () => {
					setIsBusy( false );
				} );
		},
		[ notify ]
	);

	const confirmDelete = useCallback( () => {
		if ( ! pendingDelete ) {
			return;
		}

		const item = pendingDelete;
		setIsBusy( true );
		sendCommand< { id: string; message: string } >(
			'updatebee_delete_webhook',
			{
				nonce: item.deleteNonce,
				id: item.id,
			}
		)
			.then( ( response ) => {
				setWebhooks( ( current ) =>
					current.filter(
						( webhook ) => webhook.id !== response.data.data.id
					)
				);
				setPendingDelete( null );
				notify( response.data.data.message, 'success' );
			} )
			.catch( ( error: Error ) => {
				notify(
					error.message ||
						__( 'Webhook could not be deleted.', 'updatebee' ),
					'error'
				);
			} )
			.finally( () => {
				setIsBusy( false );
			} );
	}, [ notify, pendingDelete ] );

	const fields = useMemo< Field< WebhookListItem >[] >(
		() => [
			{
				id: 'name',
				type: 'text',
				label: __( 'Name', 'updatebee' ),
				enableGlobalSearch: true,
				enableSorting: true,
			},
			{
				id: 'enabledLabel',
				type: 'text',
				label: __( 'Status', 'updatebee' ),
				enableGlobalSearch: true,
				enableSorting: true,
			},
			{
				id: 'url',
				type: 'text',
				label: __( 'Webhook URL', 'updatebee' ),
				enableGlobalSearch: true,
			},
			{
				id: 'reportsLabel',
				type: 'text',
				label: __( 'Reports', 'updatebee' ),
				enableGlobalSearch: true,
			},
			{
				id: 'lastDeliveryLabel',
				type: 'text',
				label: __( 'Last delivery', 'updatebee' ),
				enableGlobalSearch: false,
			},
		],
		[]
	);

	const actions = useMemo(
		() =>
			getWebhookActions( {
				onToggle: toggleWebhook,
				onDelete: setPendingDelete,
			} ),
		[ toggleWebhook ]
	);

	const searchLabel = __( 'Search webhooks', 'updatebee' );
	const { data: visibleWebhooks, paginationInfo } = useMemo(
		() => filterSortAndPaginate( webhooks, view, fields ),
		[ webhooks, view, fields ]
	);

	if ( isLoading ) {
		return <Loader />;
	}

	if ( isError ) {
		return (
			<ErrorFallback
				heading={ __( 'Webhooks could not be loaded.', 'updatebee' ) }
				message={ __(
					'An unexpected error occurred while loading webhooks. Try again or reload the page.',
					'updatebee'
				) }
			/>
		);
	}

	return (
		<div className="updatebee-webhooks-main">
			<Card.Root className="updatebee-webhooks-card">
				<Card.Header>
					<div className="updatebee-webhooks-heading">
						<div>
							<Card.Title>
								{ __( 'Webhooks', 'updatebee' ) }
							</Card.Title>
							<p className="description">
								{ __(
									'Send JSON update reports to an HTTPS URL when updates succeed, fail, or are available.',
									'updatebee'
								) }
							</p>
						</div>
						<Button
							variant="primary"
							href={ updatebeeWebhooks.newUrl }
						>
							{ __( 'Add webhook', 'updatebee' ) }
						</Button>
					</div>
				</Card.Header>
				<Card.Content>
					<DataViews
						data={ visibleWebhooks }
						fields={ fields }
						view={ view }
						onChangeView={ onChangeView }
						onReset={ onReset }
						actions={ actions }
						isLoading={ isBusy }
						paginationInfo={ paginationInfo }
						getItemId={ ( item ) => item.id }
						defaultLayouts={ {
							table: {
								layout: {
									density: 'balanced',
									styles: tableColumnStyles,
								},
							},
						} }
						empty={
							<p>
								{ 0 === webhooks.length
									? __(
											'No webhooks have been added yet.',
											'updatebee'
										)
									: __(
											'No webhooks match the current search.',
											'updatebee'
										) }
							</p>
						}
					>
						<WebhooksDataViews
							searchLabel={ searchLabel }
							snackbar={ snackbar }
							onSnackbarClose={ () =>
								setSnackbar( ( current ) => ( {
									...current,
									isVisible: false,
								} ) )
							}
						/>
					</DataViews>
				</Card.Content>
			</Card.Root>
			{ pendingDelete && (
				<Modal
					title={ __( 'Delete webhook', 'updatebee' ) }
					onRequestClose={ () => setPendingDelete( null ) }
				>
					<p>
						{ __(
							'Deleting this webhook cannot be undone.',
							'updatebee'
						) }
					</p>
					<div className="updatebee-webhook-edit-actions">
						<Button
							variant="primary"
							isDestructive
							isBusy={ isBusy }
							disabled={ isBusy }
							onClick={ confirmDelete }
						>
							{ __( 'Delete', 'updatebee' ) }
						</Button>
						<Button
							variant="secondary"
							disabled={ isBusy }
							onClick={ () => setPendingDelete( null ) }
						>
							{ __( 'Cancel', 'updatebee' ) }
						</Button>
					</div>
				</Modal>
			) }
		</div>
	);
};

export default WebhooksList;
