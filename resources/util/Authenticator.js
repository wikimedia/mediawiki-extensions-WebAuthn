( function( $, mw ) {
	mw.ext.webauthn.Authenticator = function( authInfo ) {
		OO.EventEmitter.call( this );
		this.authInfo = authInfo || null;
	};

	OO.initClass( mw.ext.webauthn.Authenticator );
	OO.mixinClass( mw.ext.webauthn.Authenticator, OO.EventEmitter );

	mw.ext.webauthn.Authenticator.prototype.authenticate = function() {
		var dfd = $.Deferred();
		if ( this.authInfo === null ) {
			this.getAuthInfo().done( function( response ) {
				if ( !response['webauthn'].hasOwnProperty( 'auth_info' ) ) {
					dfd.reject( 'webauthn-error-get-authinfo-fail' );
				}
				this.authInfo = response['webauthn'].auth_info;
				this.authInfo = JSON.parse( this.authInfo );
				this.authenticateWithAuthInfo( dfd );
			}.bind( this ) ).fail( function( error ) {
					dfd.reject( error );
				}.bind( this )
			);
		} else {
			this.authenticateWithAuthInfo( dfd );
		}
		return dfd.promise();
	};

	mw.ext.webauthn.Authenticator.prototype.getAuthInfo = function() {
		return new mw.Api().get( {
			action: 'webauthn',
			func: 'getAuthInfo'
		} );
	};

	mw.ext.webauthn.Authenticator.prototype.authenticateWithAuthInfo = function( dfd ) {
		// At this point we assume authInfo is set
		this.getCredentials()
			.then( function( assertion ) {
				dfd.resolve( this.formatCredential( assertion ) );
			}.bind( this ) )
			.catch( function( error ) {
				// This usually happens when process gets interrupted
				// - show generic interrupt error
				dfd.reject( "webauthn-error-auth-generic" );
			}.bind( this ) );
	};

	mw.ext.webauthn.Authenticator.prototype.getCredentials = function() {
		var publicKey = this.authInfo;
		publicKey.challenge = Uint8Array.from(
			window.atob( mw.ext.webauthn.util.base64url2base64( publicKey.challenge ) ), c => c.charCodeAt( 0 )
		);

		publicKey.allowCredentials = publicKey.allowCredentials.map( function( data ) {
			return $.extend( data, {
				'id': Uint8Array.from( atob( mw.ext.webauthn.util.base64url2base64( data.id ) ), c => c.charCodeAt( 0 ) )
			} );
		} );

		return navigator.credentials.get( { "publicKey": publicKey } );
	};

	mw.ext.webauthn.Authenticator.prototype.formatCredential = function( assertion ) {
		this.credential =  {
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
					this.arrayToBase64String( new Uint8Array( assertion.response.userHandle ) ) : null
			}
		};
		return this.credential;
	};

	mw.ext.webauthn.Authenticator.prototype.arrayToBase64String = function( a ) {
		return btoa( String.fromCharCode( ...a ) );
	};
} ) ( jQuery, mediaWiki );
