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

use MediaWiki\Auth\AuthenticationRequest;
use RawMessage;

class WebAuthnAuthenticationRequest extends AuthenticationRequest {
	/**
	 * @var string
	 */
	protected $authInfo;

	/**
	 * @var string
	 */
	protected $credential;

	/**
	 * @return array
	 */
	public function describeCredentials() {
		return [
			'provider' => wfMessage( 'oathauth-describe-provider' ),
			'account' => new RawMessage( '$1', [ $this->username ] ),
		] + parent::describeCredentials();
	}

	/**
	 * Set the authentication data to be passed
	 * to the client for credential retrieval
	 *
	 * @param string $info
	 */
	public function setAuthInfo( $info ) {
		$this->authInfo = $info;
	}

	/**
	 * @return array
	 */
	public function getFieldInfo() {
		return [
			'auth_info' => [
				'type' => 'hidden',
				'value' => $this->authInfo
			],
			'credential' => [
				'type' => 'hidden',
				'value' => ''
			]
		];
	}

	/**
	 * Loads the credential retrieved from the client
	 *
	 * @param array $data
	 * @return bool
	 */
	public function loadFromSubmission( array $data ) {
		if ( !isset( $data['credential'] ) ) {
			return false;
		}
		$this->credential = $data['credential'];

		return true;
	}

	/**
	 * @return array
	 */
	public function getSubmittedData() {
		return [
			'credential' => $this->credential
		];
	}
}
