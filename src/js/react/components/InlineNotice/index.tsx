import { useEffect, useRef } from 'react';
import { Fill } from '@wordpress/components';
import { Notice } from '@wordpress/ui';

export const NOTICES_SLOT = 'updatebeeNotices';

interface InlineNoticeProps {
	isVisible: boolean;
	message: string;
	type?: 'success' | 'error' | 'warning' | 'info';
	isPersistent?: boolean;
	onClose: () => void;
}

const InlineNotice = ( props: InlineNoticeProps ) => {
	const {
		isVisible,
		message,
		type = 'success',
		isPersistent = false,
		onClose,
	} = props;
	const ref = useRef< HTMLDivElement >( null );

	useEffect( () => {
		if ( isVisible && ! isPersistent && 'error' !== type ) {
			const timer = setTimeout( onClose, 8000 );
			return () => clearTimeout( timer );
		}
	}, [ isVisible, isPersistent, type, onClose ] );

	useEffect( () => {
		if ( isVisible ) {
			ref.current?.scrollIntoView( { block: 'nearest' } );
		}
	}, [ isVisible, message ] );

	if ( ! isVisible ) {
		return null;
	}

	return (
		<Fill name={ NOTICES_SLOT }>
			<div ref={ ref } className="updatebee-inline-notice">
				<Notice.Root intent={ type }>
					<Notice.Description>{ message }</Notice.Description>
					<Notice.CloseIcon onClick={ onClose } />
				</Notice.Root>
			</div>
		</Fill>
	);
};

export default InlineNotice;
