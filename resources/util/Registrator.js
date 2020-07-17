( function () {
	mw.ext.webauthn.Registrator = function ( friendlyName, registerData ) {
		OO.EventEmitter.call( this );
		this.friendlyName = friendlyName;
		this.registerData = registerData || null;
	};

	OO.initClass( mw.ext.webauthn.Registrator );
	OO.mixinClass( mw.ext.webauthn.Registrator, OO.EventEmitter );

	mw.ext.webauthn.Registrator.prototype.register = function () {
		const dfd = $.Deferred();
		if ( this.registerData === null ) {
			this.getRegisterInfo().then(
				function ( response ) {
					if ( !response.webauthn.hasOwnProperty( 'register_info' ) ) {
						dfd.reject( 'webauthn-error-get-reginfo-fail' );
					}
					this.registerData = response.webauthn.register_info;
					this.registerData = JSON.parse( this.registerData );
					this.registerWithRegisterInfo( dfd );
				}.bind( this ),
				function ( error ) {
					dfd.reject( error );
				}
			);
		} else {
			this.registerWithRegisterInfo( dfd );
		}
		return dfd.promise();
	};

	mw.ext.webauthn.Registrator.prototype.getRegisterInfo = function () {
		return new mw.Api().get( {
			action: 'webauthn',
			func: 'getRegisterInfo'
		} );
	};

	mw.ext.webauthn.Registrator.prototype.registerWithRegisterInfo = function ( dfd ) {
		this.createCredential()
			.then( function ( assertion ) {
				dfd.resolve( this.formatCredential( assertion ) );
			}.bind( this ) )
			.catch( function () {
				// This usually happens when process gets interrupted
				// - show generic interrupt error
				dfd.reject( 'webauthn-error-reg-generic' );
			} );
	};

	mw.ext.webauthn.Registrator.prototype.createCredential = function () {
		const publicKey = this.registerData;
		publicKey.challenge = Uint8Array.from(
			window.atob( mw.ext.webauthn.util.base64url2base64( publicKey.challenge ) ),
			function ( c ) { return c.charCodeAt( 0 ); }
		);
		publicKey.user.id = Uint8Array.from(
			window.atob( publicKey.user.id ),
			function ( c ) { return c.charCodeAt( 0 ); }
		);

		if ( publicKey.excludeCredentials ) {
			publicKey.excludeCredentials = publicKey.excludeCredentials.map( function ( data ) {
				return $.extend( data, {
					id: Uint8Array.from(
						window.atob( mw.ext.webauthn.util.base64url2base64( data.id ) ),
						function ( c ) { return c.charCodeAt( 0 ); }
					)
				} );
			} );
		}

		this.emit( 'userPrompt' );
		return navigator.credentials.create( { publicKey: publicKey } );
	};

	mw.ext.webauthn.Registrator.prototype.formatCredential = function ( newCredential ) {
		this.credential = {
			friendlyName: this.friendlyName,
			id: newCredential.id,
			type: newCredential.type,
			rawId: this.arrayToBase64String( new Uint8Array( newCredential.rawId ) ),
			response: {
				clientDataJSON: this.arrayToBase64String(
					new Uint8Array( newCredential.response.clientDataJSON )
				),
				attestationObject: this.arrayToBase64String(
					new Uint8Array( newCredential.response.attestationObject )
				)
			}
		};

		return this.credential;
	};

	mw.ext.webauthn.Registrator.prototype.arrayToBase64String = function ( a ) {
		let strigified = '';
		for ( let i = 0; i < a.length; i++ ) {
			strigified += String.fromCharCode( a[ i ] );
		}
		return btoa( strigified );
	};
}() );
