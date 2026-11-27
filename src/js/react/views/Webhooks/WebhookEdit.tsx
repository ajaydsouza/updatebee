/**
 * Create or edit one outgoing webhook.
 */
import {
	Button,
	CheckboxControl,
	Notice,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Card } from '@wordpress/ui';
import sendCommand from '../../../utils/SendCommand';
import { HEADER_NAME_OPTIONS } from './header-presets';
import {
	REPORT_FIELDS,
	TYPE_FIELDS,
	reportLabel,
	webhookEditUrl,
} from './labels';
import {
	defaultFormValues,
	emptyHeader,
	type WebhookEditor,
	type WebhookFormValues,
	type WebhookHeader,
	type WebhookMethod,
} from './types';
import Loader from '../../components/Loader';

type WebhookEditProps = {
	webhookId: string;
};

const formFromEditor = ( webhook: WebhookEditor ): WebhookFormValues => ( {
	name: webhook.name,
	url: webhook.url,
	enabled: webhook.enabled,
	method: webhook.method,
	headers: webhook.headers.length > 0 ? webhook.headers : [ emptyHeader() ],
	reports: webhook.reports,
	types: webhook.types,
} );

const WebhookEdit = ( props: WebhookEditProps ) => {
	const { webhookId } = props;
	const isNew = 'new' === webhookId || '' === webhookId;
	const [ isLoading, setIsLoading ] = useState( ! isNew );
	const [ isMissing, setIsMissing ] = useState( false );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ isTesting, setIsTesting ] = useState( false );
	const [ form, setForm ] =
		useState< WebhookFormValues >( defaultFormValues );
	const [ notice, setNotice ] = useState< {
		status: 'success' | 'error';
		message: string;
	} | null >( null );

	useEffect( () => {
		if ( isNew ) {
			return;
		}

		let ignore = false;
		sendCommand< { webhook: WebhookEditor } >(
			'updatebee_retrieve_webhook',
			{
				nonce: updatebeeWebhooks.retrieveNonce,
				id: webhookId,
			}
		)
			.then( ( response ) => {
				if ( ! ignore ) {
					setForm( formFromEditor( response.data.data.webhook ) );
				}
			} )
			.catch( () => {
				if ( ! ignore ) {
					setIsMissing( true );
				}
			} )
			.finally( () => {
				if ( ! ignore ) {
					setIsLoading( false );
				}
			} );

		return () => {
			ignore = true;
		};
	}, [ isNew, webhookId ] );

	const updateHeader = ( index: number, patch: Partial< WebhookHeader > ) => {
		setForm( ( current ) => ( {
			...current,
			headers: current.headers.map( ( header, headerIndex ) =>
				headerIndex === index ? { ...header, ...patch } : header
			),
		} ) );
	};

	const validate = (): string => {
		if ( '' === form.name.trim() ) {
			return __( 'Enter a name for this webhook.', 'updatebee' );
		}
		if ( ! form.url.trim().startsWith( 'https://' ) ) {
			return __( 'Enter an HTTPS webhook URL.', 'updatebee' );
		}
		return '';
	};

	const requestBody = () => ( {
		id: isNew ? '' : webhookId,
		name: form.name,
		url: form.url,
		enabled: form.enabled,
		method: form.method,
		headers: form.headers,
		reports: form.reports,
		types: form.types,
	} );

	const saveWebhook = () => {
		const error = validate();
		if ( error ) {
			setNotice( { status: 'error', message: error } );
			return;
		}

		setIsSaving( true );
		setNotice( null );
		sendCommand< { message: string; webhook: WebhookEditor } >(
			'updatebee_save_webhook',
			{
				nonce: updatebeeWebhooks.saveNonce,
				...requestBody(),
			}
		)
			.then( ( response ) => {
				const saved = response.data.data.webhook;
				if ( isNew && saved.id ) {
					window.location.assign( webhookEditUrl( saved.id ) );
					return;
				}
				setForm( formFromEditor( saved ) );
				setNotice( {
					status: 'success',
					message: response.data.data.message,
				} );
			} )
			.catch( ( requestError: Error ) => {
				setNotice( {
					status: 'error',
					message:
						requestError.message ||
						__( 'Webhook could not be saved.', 'updatebee' ),
				} );
			} )
			.finally( () => {
				setIsSaving( false );
			} );
	};

	const sendTest = () => {
		const error = validate();
		if ( error ) {
			setNotice( { status: 'error', message: error } );
			return;
		}

		setIsTesting( true );
		setNotice( null );
		sendCommand< { message: string } >( 'updatebee_send_webhook_test', {
			nonce: updatebeeWebhooks.testNonce,
			...requestBody(),
		} )
			.then( ( response ) => {
				setNotice( {
					status: 'success',
					message: response.data.data.message,
				} );
			} )
			.catch( ( requestError: Error ) => {
				setNotice( {
					status: 'error',
					message:
						requestError.message ||
						__( 'The webhook request failed.', 'updatebee' ),
				} );
			} )
			.finally( () => {
				setIsTesting( false );
			} );
	};

	if ( isLoading ) {
		return <Loader />;
	}

	if ( isMissing ) {
		return (
			<div className="updatebee-webhook-edit">
				<p>{ __( 'Webhook could not be found.', 'updatebee' ) }</p>
				<Button variant="secondary" href={ updatebeeWebhooks.listUrl }>
					{ __( 'Back to webhooks', 'updatebee' ) }
				</Button>
			</div>
		);
	}

	const busy = isSaving || isTesting;
	const renderFormNotice = () =>
		notice ? (
			<Notice
				status={ notice.status }
				isDismissible
				onRemove={ () => setNotice( null ) }
			>
				{ notice.message }
			</Notice>
		) : null;

	return (
		<div className="updatebee-webhook-edit">
			<Card.Root>
				<Card.Header>
					<div className="updatebee-webhook-edit-header">
						<Button
							variant="link"
							href={ updatebeeWebhooks.listUrl }
						>
							{ __( 'Back to webhooks', 'updatebee' ) }
						</Button>
						<h2 className="updatebee-webhook-edit-title">
							{ isNew
								? __( 'Add webhook', 'updatebee' )
								: __( 'Edit webhook', 'updatebee' ) }
						</h2>
						<p className="description">
							{ __(
								'Each report is one JSON request. Successful updates include security releases. Available updates follow the Available Updates email schedule, including Send now. If that schedule is off, the available-updates report is not sent.',
								'updatebee'
							) }
						</p>
					</div>
				</Card.Header>
				<Card.Content>
					{ renderFormNotice() }
					<TextControl
						label={ __( 'Name', 'updatebee' ) }
						value={ form.name }
						onChange={ ( name ) =>
							setForm( ( current ) => ( { ...current, name } ) )
						}
						help={ __( 'Shown in the webhook list.', 'updatebee' ) }
						required
					/>
					<ToggleControl
						className="updatebee-webhook-enabled"
						label={ __( 'Enabled', 'updatebee' ) }
						checked={ form.enabled }
						onChange={ ( enabled ) =>
							setForm( ( current ) => ( {
								...current,
								enabled,
							} ) )
						}
						help={ __(
							'Disabled webhooks stay in the list and are not sent.',
							'updatebee'
						) }
					/>
					<TextControl
						label={ __( 'Webhook URL', 'updatebee' ) }
						type="url"
						value={ form.url }
						onChange={ ( url ) =>
							setForm( ( current ) => ( { ...current, url } ) )
						}
						help={ __(
							'HTTPS only. The JSON report is posted to this URL.',
							'updatebee'
						) }
						required
						autoComplete="off"
					/>
					<SelectControl
						label={ __( 'Request method', 'updatebee' ) }
						value={ form.method }
						options={ [
							{ label: 'POST', value: 'POST' },
							{ label: 'PUT', value: 'PUT' },
							{ label: 'PATCH', value: 'PATCH' },
						] }
						onChange={ ( method ) =>
							setForm( ( current ) => ( {
								...current,
								method: method as WebhookMethod,
							} ) )
						}
						help={ __( 'The body is always JSON.', 'updatebee' ) }
					/>
					<h3>{ __( 'Request headers', 'updatebee' ) }</h3>
					<p className="description updatebee-webhook-headers-help">
						{ __(
							'Add an Authorization header or another static header. Content-Type is set to JSON.',
							'updatebee'
						) }
					</p>
					{ form.headers.map( ( header, index ) => (
						<div
							className="updatebee-webhook-header-row"
							key={ `header-${ index }` }
						>
							<div>
								<SelectControl
									label={ __( 'Name', 'updatebee' ) }
									value={ header.name }
									options={ HEADER_NAME_OPTIONS }
									onChange={ ( name ) =>
										updateHeader( index, { name } )
									}
								/>
								{ 'custom' === header.name && (
									<TextControl
										label={ __(
											'Custom header name',
											'updatebee'
										) }
										value={ header.customName }
										onChange={ ( customName ) =>
											updateHeader( index, {
												customName,
											} )
										}
										autoComplete="off"
									/>
								) }
							</div>
							<TextControl
								label={ __( 'Value', 'updatebee' ) }
								value={ header.value }
								onChange={ ( value ) =>
									updateHeader( index, { value } )
								}
								autoComplete="off"
							/>
							<div className="updatebee-webhook-header-actions">
								<Button
									icon="plus"
									label={ __( 'Add header', 'updatebee' ) }
									disabled={ form.headers.length >= 20 }
									onClick={ () =>
										setForm( ( current ) => ( {
											...current,
											headers: [
												...current.headers,
												emptyHeader(),
											],
										} ) )
									}
								/>
								{ form.headers.length > 1 && (
									<Button
										icon="minus"
										label={ __(
											'Remove header',
											'updatebee'
										) }
										onClick={ () =>
											setForm( ( current ) => ( {
												...current,
												headers: current.headers.filter(
													( _header, headerIndex ) =>
														headerIndex !== index
												),
											} ) )
										}
									/>
								) }
							</div>
						</div>
					) ) }
					<h3>{ __( 'Reports', 'updatebee' ) }</h3>
					<div className="updatebee-webhook-checks">
						{ REPORT_FIELDS.map( ( report ) => (
							<ToggleControl
								key={ report }
								label={ reportLabel( report ) }
								checked={ form.reports[ report ] }
								onChange={ ( checked ) =>
									setForm( ( current ) => ( {
										...current,
										reports: {
											...current.reports,
											[ report ]: checked,
										},
									} ) )
								}
								help={
									'available' === report
										? __(
												'Sent when the Available Updates email runs.',
												'updatebee'
											)
										: undefined
								}
							/>
						) ) }
					</div>
					<h3>{ __( 'Update types', 'updatebee' ) }</h3>
					<div className="updatebee-webhook-checks">
						{ TYPE_FIELDS.map( ( type ) => (
							<CheckboxControl
								key={ type.key }
								label={ type.label }
								checked={ form.types[ type.key ] }
								onChange={ ( checked ) =>
									setForm( ( current ) => ( {
										...current,
										types: {
											...current.types,
											[ type.key ]: checked,
										},
									} ) )
								}
							/>
						) ) }
					</div>
					<div className="updatebee-webhook-edit-actions">
						{ renderFormNotice() }
						<div className="updatebee-webhook-edit-buttons">
							<Button
								variant="primary"
								isBusy={ isSaving }
								disabled={ busy }
								onClick={ saveWebhook }
							>
								{ __( 'Save webhook', 'updatebee' ) }
							</Button>
							<Button
								variant="secondary"
								isBusy={ isTesting }
								disabled={ busy }
								onClick={ sendTest }
							>
								{ __( 'Send test', 'updatebee' ) }
							</Button>
						</div>
					</div>
				</Card.Content>
			</Card.Root>
		</div>
	);
};

export default WebhookEdit;
