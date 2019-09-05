<?php
/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 */

namespace MediaWiki\Extension\WebAuthn\Auth;

use MediaWiki\Auth\AbstractSecondaryAuthenticationProvider;
use MediaWiki\Auth\AuthenticationRequest;
use MediaWiki\Auth\AuthenticationResponse;
use MediaWiki\Extension\WebAuthn\Authenticator;
use RequestContext;

class WebAuthnSecondaryAuthenticationProvider extends AbstractSecondaryAuthenticationProvider {

	/**
	 * @param string $action
	 * @param array $options
	 *
	 * @return array
	 */
	public function getAuthenticationRequests( $action, array $options ) {
		return [];
	}

	/**
	 * @inheritDoc
	 */
	public function beginSecondaryAuthentication( $user, array $reqs ) {
		$authenticator = Authenticator::factory( $user, $this->manager->getRequest() );
		if ( !$authenticator->isEnabled() ) {
			return AuthenticationResponse::newAbstain();
		}
		$canAuthenticate = $authenticator->canAuthenticate();
		if ( !$canAuthenticate->isGood() ) {
			return AuthenticationResponse::newFail( $canAuthenticate->getMessage() );
		}
		$request = new WebAuthnAuthenticationRequest();
		$startAuthResult = $authenticator->startAuthentication();
		if ( $startAuthResult->isGood() ) {
			$request->setAuthInfo( $startAuthResult->getValue()['json'] );
			$this->addModules();
			return AuthenticationResponse::newUI( [ $request ],
				wfMessage( 'webauthn-ui-login-prompt' ) );
		}
		return AuthenticationResponse::newFail( $startAuthResult->getMessage() );
	}

	/**
	 * @inheritDoc
	 */
	public function continueSecondaryAuthentication( $user, array $reqs ) {
		$authenticator = Authenticator::factory( $user, $this->manager->getRequest() );
		$canAuthenticate = $authenticator->canAuthenticate();
		if ( !$canAuthenticate->isGood() ) {
			return AuthenticationResponse::newFail( $canAuthenticate->getMessage() );
		}

		/** @var WebAuthnAuthenticationRequest $request */
		$request = AuthenticationRequest::getRequestByClass(
			$reqs,
			WebAuthnAuthenticationRequest::class
		);
		if ( !$request ) {
			// Re-ask user for credentials
			$request = new WebAuthnAuthenticationRequest();
			$request->setAuthInfo( $authenticator->startAuthentication()->getValue()['json'] );
			$this->addModules();
			return AuthenticationResponse::newUI( [ $request ],
				wfMessage( 'oathauth-login-failed' ), 'error' );
		}

		// Get credential retrieved from the client
		$verificationData = $request->getSubmittedData();
		$authResult = $authenticator->continueAuthentication( $verificationData );
		if ( $authResult->isGood() ) {
			return AuthenticationResponse::newPass( $authResult->getValue()->getUser()->getName() );
		}
		return AuthenticationResponse::newFail( wfMessage( 'oathauth-login-failed' ) );
	}

	protected function addModules() {
		// It would be better to add modules in HTMLFormField class
		// but that does not seem to work for login form
		$out = RequestContext::getMain()->getOutput();
		$out->addModules( "ext.webauthn.login" );
	}

	/**
	 * @inheritDoc
	 */
	public function beginSecondaryAccountCreation( $user, $creator, array $reqs ) {
		return AuthenticationResponse::newAbstain();
	}
}
