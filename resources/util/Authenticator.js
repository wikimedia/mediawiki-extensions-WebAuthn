mw.ext.webauthn.Authenticator = function ( authInfo ) {
	OO.EventEmitter.call( this );
	this.authInfo = authInfo || null;
};

OO.initClass( mw.ext.webauthn.Authenticator );
OO.mixinClass( mw.ext.webauthn.Authenticator, OO.EventEmitter );

mw.ext.webauthn.Authenticator.prototype.authenticate = function () {
	const dfd = $.Deferred();
	if ( this.authInfo === null ) {
		this.getAuthInfo().done( ( response ) => {
			if ( !response.webauthn.hasOwnProperty( 'auth_info' ) ) {
				dfd.reject( 'webauthn-error-get-authinfo-fail' );
			}
			this.authInfo = response.webauthn.auth_info;
			this.authInfo = JSON.parse( this.authInfo );
			this.authenticateWithAuthInfo( dfd );
		} ).fail( ( error ) => {
			dfd.reject( error );
		}
		);
	} else {
		this.authenticateWithAuthInfo( dfd );
	}
	return dfd.promise();
};

mw.ext.webauthn.Authenticator.prototype.getAuthInfo = function () {
	return new mw.Api().get( {
		action: 'webauthn',
		func: 'getAuthInfo'
	} );
};

mw.ext.webauthn.Authenticator.prototype.authenticateWithAuthInfo = function ( dfd ) {
	// At this point we assume authInfo is set
	this.getCredentials()
		.then( ( assertion ) => {
			dfd.resolve( this.formatCredential( assertion ) );
		} )
		.catch( () => {
			// This usually happens when the process gets interrupted
			// - show generic interrupt error
			dfd.reject( 'webauthn-error-auth-generic' );
		} );
};

mw.ext.webauthn.Authenticator.prototype.getCredentials = function () {
	const publicKey = this.authInfo;
	publicKey.challenge = Uint8Array.from(
		window.atob( mw.ext.webauthn.util.base64url2base64( publicKey.challenge ) ),
		( c ) => c.charCodeAt( 0 )
	);

	publicKey.allowCredentials = publicKey.allowCredentials.map( ( data ) => Object.assign( data, {
		id: Uint8Array.from(
			atob( mw.ext.webauthn.util.base64url2base64( data.id ) ),
			( c ) => c.charCodeAt( 0 )
		)
	} ) );

	return navigator.credentials.get( { publicKey: publicKey } );
};

mw.ext.webauthn.Authenticator.prototype.formatCredential = function ( assertion ) {
	this.credential = {
		id: assertion.id,
		type: assertion.type,
		rawId: this.arrayToBase64String( new Uint8Array( assertion.rawId ) ),
		response: {
			authenticatorData: this.arrayToBase64String(
				new Uint8Array( assertion.response.authenticatorData )
			),
			clientDataJSON: this.arrayToBase64String(
				new Uint8Array( assertion.response.clientDataJSON )
			),
			signature: this.arrayToBase64String(
				new Uint8Array( assertion.response.signature )
			),
			userHandle: assertion.response.userHandle ?
				this.arrayToBase64String( new Uint8Array( assertion.response.userHandle ) ) :
				null
		}
	};
	return this.credential;
};

mw.ext.webauthn.Authenticator.prototype.arrayToBase64String = function ( a ) {
	let stringified = '';
	for ( let i = 0; i < a.length; i++ ) {
		stringified += String.fromCharCode( a[ i ] );
	}
	return btoa( stringified );
};
