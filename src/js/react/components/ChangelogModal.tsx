/**
 * Changelog for one plugin or theme.
 */
import { Modal, Notice } from '@wordpress/components';
import { RawHTML, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import sendCommand from '../../utils/SendCommand';

export type ChangelogKind = 'plugin' | 'theme';

export type ChangelogTarget = {
	kind: ChangelogKind;
	id: string;
	name: string;
	nonce: string;
};

type ChangelogPayload = {
	html?: string;
};

type ChangelogModalProps = {
	target: ChangelogTarget | null;
	onClose: () => void;
};

/**
 * Dismissible modal that loads a plugin or theme changelog.
 *
 * @param props Modal state.
 */
const ChangelogModal = ( props: ChangelogModalProps ) => {
	const { target, onClose } = props;
	const [ html, setHtml ] = useState( '' );
	const [ isLoading, setIsLoading ] = useState( false );
	const [ error, setError ] = useState( '' );

	const targetKind = target?.kind;
	const targetId = target?.id;
	const targetNonce = target?.nonce;

	useEffect( () => {
		if ( ! targetKind || ! targetId || ! targetNonce ) {
			return undefined;
		}

		let cancelled = false;
		const action =
			'plugin' === targetKind
				? 'updatebee_plugin_changelog'
				: 'updatebee_theme_changelog';
		const idField = 'plugin' === targetKind ? 'file' : 'stylesheet';

		setIsLoading( true );
		setError( '' );
		setHtml( '' );

		sendCommand< ChangelogPayload >( action, {
			nonce: targetNonce,
			[ idField ]: targetId,
		} )
			.then( ( response ) => {
				if ( cancelled ) {
					return;
				}
				const next = response.data.data.html;
				setHtml( 'string' === typeof next ? next : '' );
			} )
			.catch( ( loadError: Error ) => {
				if ( cancelled ) {
					return;
				}
				setError(
					loadError.message ||
						__( 'The changelog could not be loaded.', 'updatebee' )
				);
			} )
			.finally( () => {
				if ( ! cancelled ) {
					setIsLoading( false );
				}
			} );

		return () => {
			cancelled = true;
		};
	}, [ targetKind, targetId, targetNonce ] );

	if ( ! target ) {
		return null;
	}

	let body = null;
	if ( isLoading ) {
		body = (
			<p className="updatebee-changelog-loading">
				{ __( 'Loading changelog…', 'updatebee' ) }
			</p>
		);
	} else if ( '' !== html ) {
		body = <RawHTML className="updatebee-changelog-body">{ html }</RawHTML>;
	} else if ( '' === error ) {
		body = <p>{ __( 'No changelog is available.', 'updatebee' ) }</p>;
	}

	return (
		<Modal
			className="updatebee-changelog-modal"
			title={ sprintf(
				/* translators: %s: Plugin or theme name. */
				__( 'Changelog: %s', 'updatebee' ),
				target.name
			) }
			onRequestClose={ onClose }
			shouldCloseOnClickOutside
			shouldCloseOnEsc
			isDismissible
		>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			{ body }
		</Modal>
	);
};

export default ChangelogModal;
