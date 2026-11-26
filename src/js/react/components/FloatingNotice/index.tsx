import NoticeBanner, { type NoticeBannerProps } from '../NoticeBanner';

interface FloatingNoticeProps extends Omit< NoticeBannerProps, 'message' > {
	isVisible: boolean;
	message: string;
}

const FloatingNotice = ( props: FloatingNoticeProps ) => {
	const { isVisible, message, ...rest } = props;

	if ( ! isVisible ) {
		return null;
	}

	return (
		<div className="updatebee-floating-notice">
			<NoticeBanner message={ message } { ...rest } />
		</div>
	);
};

export default FloatingNotice;
