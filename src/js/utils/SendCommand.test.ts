import axios from 'axios';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import sendCommand from './SendCommand';

vi.mock( 'axios', () => ( {
	default: vi.fn(),
} ) );

describe( 'sendCommand empty arrays', () => {
	beforeEach( () => {
		( globalThis as unknown as { ajaxurl: string } ).ajaxurl =
			'/wp-admin/admin-ajax.php';
		vi.mocked( axios ).mockResolvedValue( {
			data: { success: true, data: {} },
		} as never );
	} );

	it( 'omits empty arrays unless this request asks to keep them', async () => {
		await sendCommand( 'updatebee_test', {
			toField: [],
			bccField: [],
		} );
		await sendCommand(
			'updatebee_test',
			{
				toField: [],
				bccField: [],
			},
			{ allowEmptyArrays: true }
		);

		const calls = vi.mocked( axios ).mock.calls;
		const omitted = calls[ 0 ][ 0 ] as unknown as { data: string };
		const kept = calls[ 1 ][ 0 ] as unknown as { data: string };

		expect( omitted.data ).not.toContain( 'toField' );
		expect( omitted.data ).not.toContain( 'bccField' );
		expect( kept.data ).toContain( 'toField[]' );
		expect( kept.data ).toContain( 'bccField[]' );
	} );
} );
