// Runs against a FRESH site (PHP 8.3, WooCommerce from wordpress.org) with the release zip installed.
// Orders are placed through WooCommerce's real Store API checkout, so the Cash on Delivery gateway runs for real.
const { test, expect, request: pwRequest } = require( '@playwright/test' );
const { execSync } = require( 'child_process' );
const path = require( 'path' );

const root = path.resolve( __dirname, '../../../..' );
const shots = path.resolve( __dirname, '../../assets' );
// Loading WooCommerce on every WP-CLI call costs ~20s here, so skip plugins unless the command needs them.
const wp = ( args, needsPlugins = false ) =>
	execSync( `npx wp-env run cli wp ${ args }${ needsPlugins ? '' : ' --skip-plugins --skip-themes' }`, { cwd: path.join( root, 'pkgtest' ), encoding: 'utf8' } );

test.describe.configure( { mode: 'serial' } );

let productId;

test.beforeAll( () => {
	wp( 'plugin install /tmp/cod-risk-scorer.zip --force --activate', true );
	wp( "eval \"update_option( 'woocommerce_cod_settings', array( 'enabled' => 'yes', 'enable_for_virtual' => 'yes' ) );\"" );
	wp( "eval \"delete_option( 'codrisk_threshold' ); delete_option( 'codrisk_blocklist' );\"" );
	// E2E_HPOS=1 runs the whole flow on High-Performance Order Storage; otherwise on legacy post storage.
	const hposOn = process.env.E2E_HPOS ? 'yes' : 'no';
	wp( `option update woocommerce_custom_orders_table_enabled ${ hposOn }` );
	// Re-runnable: velocity rules look at the last 24 hours, so earlier runs' orders would change the scores.
	wp( 'eval "foreach ( wc_get_orders( array( \'limit\' => -1, \'return\' => \'ids\', \'status\' => array_keys( wc_get_order_statuses() ) ) ) as $id ) { wc_get_order( $id )->delete( true ); }"', true );
	wp( 'option update woocommerce_custom_orders_table_data_sync_enabled no' );
	productId = wp( 'wc product create --name="Risk Test Item" --regular_price=10 --virtual=true --user=1 --porcelain', true ).trim().split( /\s+/ ).pop();
} );

/** Place a guest COD order through the Store API; returns { order_id, status }. */
async function placeOrder( baseURL, { phone, address, email = 'buyer@example.com' } ) {
	const ctx = await pwRequest.newContext( { baseURL } );
	let r = await ctx.get( '/wp-json/wc/store/v1/cart' );
	let nonce = r.headers().nonce;
	const token = r.headers()[ 'cart-token' ];
	const headers = () => ( { Nonce: nonce, 'Cart-Token': token } );
	r = await ctx.post( '/wp-json/wc/store/v1/cart/add-item', { headers: headers(), data: { id: Number( productId ), quantity: 1 } } );
	expect( r.ok(), 'add to cart' ).toBeTruthy();
	nonce = r.headers().nonce || nonce;
	r = await ctx.post( '/wp-json/wc/store/v1/checkout', {
		headers: headers(),
		data: {
			billing_address: { first_name: 'Ali', last_name: 'Khan', address_1: address, city: 'Lahore', state: 'PB', postcode: '54000', country: 'PK', email, phone },
			payment_method: 'cod',
			payment_data: [],
		},
	} );
	const body = await r.json();
	expect( r.ok(), 'checkout: ' + JSON.stringify( body ).slice( 0, 300 ) ).toBeTruthy();
	await ctx.dispose();
	return { order_id: body.order_id, status: body.status };
}

/** Rows of this plugin's order meta, across legacy post meta and (if present) the HPOS meta table. */
function metaCount() {
	const count = ( sql ) => Number( wp( `db query "${ sql }" --skip-column-names` ).trim() );
	let n = count( "SELECT COUNT(*) FROM wp_postmeta WHERE meta_key LIKE '%codrisk%'" );
	if ( wp( `db query "SHOW TABLES LIKE 'wp_wc_orders_meta'" --skip-column-names` ).trim() ) {
		n += count( "SELECT COUNT(*) FROM wp_wc_orders_meta WHERE meta_key LIKE '%codrisk%'" );
	}
	return n;
}
/** Where any leftover rows are, for a useful failure message. */
function metaDetail() {
	const count = ( sql ) => wp( `db query "${ sql }" --skip-column-names` ).trim();
	const hpos = wp( `db query "SHOW TABLES LIKE 'wp_wc_orders_meta'" --skip-column-names` ).trim() ? count( "SELECT COUNT(*) FROM wp_wc_orders_meta WHERE meta_key LIKE '%codrisk%'" ) : 'no table';
	return `postmeta=${ count( "SELECT COUNT(*) FROM wp_postmeta WHERE meta_key LIKE '%codrisk%'" ) } orders_meta=${ hpos }`;
}

test( 'score, hold, order box, settings, blocklist, uninstall, clean database', async ( { page, baseURL } ) => {
	// Orders 1 and 2 pass; order 3 has the same phone and IP as 1 and 2 (40 + 15 = 55) and is held.
	const first = await placeOrder( baseURL, { phone: '+92 300 1234567', address: '1 First Road' } );
	const second = await placeOrder( baseURL, { phone: '0300-1234567', address: '2 Second Road' } );
	const third = await placeOrder( baseURL, { phone: '0300 123 4567', address: '3 Third Road' } );
	expect( first.status ).toBe( 'processing' );
	expect( second.status ).toBe( 'processing' );
	expect( third.status ).toBe( 'on-hold' );

	// Log in, then look at the held order.
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'password' );
	await page.click( '#wp-submit' );
	await expect( page.locator( '#wpadminbar' ) ).toBeVisible();

	const hpos = wp( 'option get woocommerce_custom_orders_table_enabled' ).trim() === 'yes';
	const editUrl = ( id ) => ( hpos ? `/wp-admin/admin.php?page=wc-orders&action=edit&id=${ id }` : `/wp-admin/post.php?post=${ id }&action=edit` );
	await page.goto( editUrl( third.order_id ) );
	const box = page.locator( '#codrisk' );
	await expect( box ).toContainText( '55 / 100' );
	await expect( box ).toContainText( 'other orders with this phone in 24 hours' );
	await expect( page.locator( '#order_status' ) ).toHaveValue( 'wc-on-hold' );
	await expect( page.locator( '.note_content, #woocommerce-order-notes' ).first() ).toContainText( 'COD Risk Scorer' );
	await page.waitForTimeout( 800 );
	await page.screenshot( { path: path.join( shots, 'screenshot-1.png' ), fullPage: true } );

	// An unscored-risk order shows a clean box.
	await page.goto( editUrl( first.order_id ) );
	await expect( page.locator( '#codrisk' ) ).toContainText( '0 / 100' );

	// Change the threshold: the next order (same phone, 55 points) is no longer held.
	await page.goto( '/wp-admin/admin.php?page=codrisk' );
	await page.fill( '#codrisk_threshold', '60' );
	await page.getByRole( 'button', { name: 'Save Changes' } ).click();
	await expect( page.locator( '#codrisk_threshold' ) ).toHaveValue( '60' );
	const fourth = await placeOrder( baseURL, { phone: '0300 123 4567', address: '4 Fourth Road' } );
	expect( fourth.status ).toBe( 'processing' );

	// Blocklist: a listed phone is held straight away (score 100).
	await page.goto( '/wp-admin/admin.php?page=codrisk' );
	await page.fill( '#codrisk_blocklist', '0399 9999999\nnot a valid entry' );
	await page.getByRole( 'button', { name: 'Save Changes' } ).click();
	await expect( page.locator( '#codrisk_blocklist' ) ).toHaveValue( '0399 9999999' );
	await page.waitForTimeout( 800 );
	await page.screenshot( { path: path.join( shots, 'screenshot-2.png' ), fullPage: true } );
	const blocked = await placeOrder( baseURL, { phone: '+92 399 9999999', address: '9 Other Road', email: 'other@example.com' } );
	expect( blocked.status ).toBe( 'on-hold' );
	await page.goto( editUrl( blocked.order_id ) );
	await expect( page.locator( '#codrisk' ) ).toContainText( '100 / 100' );

	// Positive control: the clean-database check below is only meaningful if this query can see the data.
	expect( metaCount() ).toBeGreaterThan( 0 );

	// Deactivate and delete through the UI (runs uninstall.php).
	await page.goto( '/wp-admin/plugins.php' );
	await page.click( '#deactivate-cod-risk-scorer' );
	await page.locator( '#delete-cod-risk-scorer' ).waitFor( { timeout: 60000 } );
	// The Delete link is wired up by JavaScript. Clicking before the page settles follows the plain link to a
	// "Yes, delete" confirmation screen, which deletes nothing, so wait first and then check for real.
	await page.waitForLoadState( 'networkidle' );
	page.once( 'dialog', ( d ) => d.accept() );
	await page.click( '#delete-cod-risk-scorer' );
	// WordPress leaves a placeholder row behind after an AJAX delete, so the reliable evidence is the plugin list itself.
	await expect.poll( () => wp( 'plugin list --field=name --skip-plugins --skip-themes' ).split( /\s+/ ).includes( 'cod-risk-scorer' ), { timeout: 90000, intervals: [ 3000 ] } ).toBe( false );

	// Clean database: no options, no order meta in either storage.
	expect( wp( "option list --search='codrisk*' --format=count" ).trim() ).toBe( '0' );
	// Poll: the delete runs over AJAX, so give uninstall.php a moment; report the plugin state if it never gets there.
	await expect.poll( () => metaCount(), { timeout: 90000, intervals: [ 3000 ], message: () => metaDetail() + ' | plugin: ' + wp( 'plugin list --fields=name,status --format=csv --skip-themes', true ).split( /\r?\n/ ).filter( ( l ) => /cod-risk/.test( l ) ).join( ';' ) } ).toBe( 0 );
} );
