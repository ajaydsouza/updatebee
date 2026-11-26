import { useEffect, useRef } from 'react';
import { Fill } from '@wordpress/components';
import NoticeBanner, { type NoticeBannerProps } from '../NoticeBanner';

export const NOTICES_SLOT = 'updatebeeNotices';

interface InlineNoticeProps extends Omit< NoticeBannerProps, 'message' > {
	isVisible: boolean;
	message: string;
}

const InlineNotice = ( props: InlineNoticeProps ) => {
	const { isVisible, message, ...rest } = props;
	const ref = useRef< HTMLDivElement >( null );

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
				<NoticeBanner message={ message } { ...rest } />
			</div>
		</Fill>
	);
};

export default InlineNotice;
