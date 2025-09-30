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
			( response ) => {
				if ( !response.webauthn.hasOwnProperty( 'register_info' ) ) {
					dfd.reject( 'webauthn-error-get-reginfo-fail' );
				}
				this.registerData = response.webauthn.register_info;
				this.registerData = JSON.parse( this.registerData );
				this.registerWithRegisterInfo( dfd );
			},
			( error ) => {
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
		.then( ( assertion ) => {
			// FIXME should handle null?
			dfd.resolve( this.formatCredential( assertion ) );
		} )
		.catch( ( error ) => {
			mw.log.error( error );
			// This usually happens when the process gets interrupted
			// - show generic interrupt error
			dfd.reject( 'webauthn-error-reg-generic' );
		} );
};

/**
 * @return {Promise<Credential|null>}
 */
mw.ext.webauthn.Registrator.prototype.createCredential = function () {
	const publicKey = this.registerData;
	publicKey.challenge = mw.ext.webauthn.util.base64ToByteArray( publicKey.challenge );
	publicKey.user.id = mw.ext.webauthn.util.base64ToByteArray( publicKey.user.id );

	if ( publicKey.excludeCredentials ) {
		publicKey.excludeCredentials = publicKey.excludeCredentials.map( ( data ) => Object.assign( data, {
			id: mw.ext.webauthn.util.base64ToByteArray( data.id )
		} ) );
	}

	if ( mw.config.get( 'wgWebAuthnLimitPasskeysToRoaming' ) ) {
		publicKey.hints = [ 'security-key' ];
	}

	this.emit( 'userPrompt' );
	return navigator.credentials.create( { publicKey: publicKey } );
};

/**
 * @param {Credential} newCredential
 */
mw.ext.webauthn.Registrator.prototype.formatCredential = function ( newCredential ) {
	// encoding should match PublicKeyCredentialLoader::loadArray()
	this.credential = {
		friendlyName: this.friendlyName,
		id: newCredential.id, // base64url encoded
		type: newCredential.type,
		rawId: mw.ext.webauthn.util.byteArrayToBase64( new Uint8Array( newCredential.rawId ),
			'base64', 'padded' ),
		response: {
			transports: newCredential.response.getTransports ?
				newCredential.response.getTransports() :
				// This omits AUTHENTICATOR_TRANSPORT_CABLE ('cable') for compatibility with iOS Safari
				// (tested on iOS 15, may not be needed for newer versions). (T358771)
				[ 'usb', 'nfc', 'ble', 'internal' ],
			// encoding should match CollectedClientData::createFormJson()
			clientDataJSON: mw.ext.webauthn.util.byteArrayToBase64(
				new Uint8Array( newCredential.response.clientDataJSON ), 'base64url', 'unpadded' ),
			// encoding should match AttestationObjectLoader::load()
			attestationObject: mw.ext.webauthn.util.byteArrayToBase64(
				new Uint8Array( newCredential.response.attestationObject ), 'base64', 'padded' )
		}
	};

	return this.credential;
};
