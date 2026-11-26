import { useEffect } from 'react';
import { Notice } from '@wordpress/ui';

export interface NoticeBannerProps {
	message: string;
	type?: 'success' | 'error' | 'warning' | 'info';
	isPersistent?: boolean;
	onClose: () => void;
}

const NoticeBanner = ( props: NoticeBannerProps ) => {
	const { message, type = 'success', isPersistent = false, onClose } = props;

	useEffect( () => {
		if ( ! isPersistent && 'error' !== type ) {
			const timer = setTimeout( onClose, 8000 );
			return () => clearTimeout( timer );
		}
	}, [ message, isPersistent, type, onClose ] );

	return (
		<Notice.Root intent={ type }>
			<Notice.Description>{ message }</Notice.Description>
			<Notice.CloseIcon onClick={ onClose } />
		</Notice.Root>
	);
};

export default NoticeBanner;
