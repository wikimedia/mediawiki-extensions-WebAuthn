<?php

namespace MediaWiki\Extension\WebAuthn\Module;

use MediaWiki\Auth\SecondaryAuthenticationProvider;
use MediaWiki\Extension\OATHAuth\HTMLForm\IManageForm;
use MediaWiki\Extension\OATHAuth\IAuthKey;
use MediaWiki\Extension\OATHAuth\IModule;
use MediaWiki\Extension\OATHAuth\OATHUser;
use MediaWiki\Extension\OATHAuth\OATHUserRepository;
use MediaWiki\Extension\OATHAuth\Special\OATHManage;
use MediaWiki\Extension\WebAuthn\Auth\WebAuthnSecondaryAuthenticationProvider;
use MediaWiki\Extension\WebAuthn\Config\WebAuthnConfig;
use MediaWiki\Extension\WebAuthn\HTMLForm\WebAuthnAddKeyForm;
use MediaWiki\Extension\WebAuthn\HTMLForm\WebAuthnDisableForm;
use MediaWiki\Extension\WebAuthn\HTMLForm\WebAuthnManageForm;
use MediaWiki\Extension\WebAuthn\Key\WebAuthnKey;
use Message;
use MWException;

class WebAuthn implements IModule {
	/**
	 * Custom action for the manage form
	 */
	public const ACTION_ADD_KEY = 'addkey';

	public static function factory() {
		return new static();
	}

	/**
	 * Name of the module
	 * @return string
	 */
	public function getName() {
		return 'webauthn';
	}

	/**
	 * @return Message
	 */
	public function getDisplayName() {
		return wfMessage( 'webauthn-module-label' );
	}

	/**
	 *
	 * @param array $data
	 * @return IAuthKey
	 */
	public function newKey( array $data = [] ) {
		if ( empty( $data ) ) {
			return WebAuthnKey::newKey();
		}
		return WebAuthnKey::newFromData( $data );
	}

	/**
	 * @param OATHUser $user
	 * @return array
	 * @throws MWException
	 */
	public function getDataFromUser( OATHUser $user ) {
		$keys = $user->getKeys();
		$data = [];
		foreach ( $keys as $key ) {
			if ( !$key instanceof WebAuthnKey ) {
				throw new MWException( 'webauthn-key-type-missmatch' );
			}
			$data[] = $key->jsonSerialize();
		}

		return [
			'keys' => $data
		];
	}

	/**
	 * @return SecondaryAuthenticationProvider
	 */
	public function getSecondaryAuthProvider() {
		return new WebAuthnSecondaryAuthenticationProvider();
	}

	/**
	 * Is this module currently enabled for the given user
	 * Arguably, module is enabled just by the fact its set on user
	 * but it might not be true for all future modules
	 *
	 * @param OATHUser $user
	 * @return bool
	 */
	public function isEnabled( OATHUser $user ) {
		if ( $user->getModule() instanceof WebAuthn ) {
			$key = $user->getFirstKey();
			if ( $key !== null && $key instanceof WebAuthnKey ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Run the validation for each of the registered keys
	 *
	 * @param OATHUser $user
	 * @param array $data
	 * @return bool
	 */
	public function verify( OATHUser $user, array $data ) {
		$keys = $user->getKeys();
		foreach ( $keys as $key ) {
			// Pass if any of the keys matches
			if ( $key->verify( $data, $user ) === true ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param string $action
	 * @param OATHUser $user
	 * @param OATHUserRepository $repo
	 * @return IManageForm|null if no form is available for given action
	 */
	public function getManageForm( $action, OATHUser $user, OATHUserRepository $repo ) {
		$module = $this;
		$enabledForUser = $user->getModule() instanceof self;
		if ( $action === OATHManage::ACTION_DISABLE && $enabledForUser ) {
			return new WebAuthnDisableForm( $user, $repo, $module );
		}
		if ( $action === OATHManage::ACTION_ENABLE && !$enabledForUser ) {
			return new WebAuthnAddKeyForm( $user, $repo, $module );
		}
		if ( $action === static::ACTION_ADD_KEY && $enabledForUser ) {
			return new WebAuthnAddKeyForm( $user, $repo, $module );
		}

		if ( $enabledForUser ) {
			return new WebAuthnManageForm( $user, $repo, $module );
		}

		return null;
	}

	/**
	 * @param string $id
	 * @param OATHUser $user
	 * @return IAuthKey|null
	 */
	public function findKeyByCredentialId( $id, $user ) {
		foreach ( $user->getKeys() as $key ) {
			if ( !( $key instanceof WebAuthnKey ) ) {
				continue;
			}
			if ( $key->getAttestedCredentialData()->getCredentialId() === $id ) {
				return $key;
			}
		}
		return null;
	}

	/**
	 * Remove single key by its friendly name
	 * This will just make changes in memory, not persist them!
	 *
	 * @param string $name
	 * @param OATHUser $user
	 *
	 * @return bool
	 */
	public function removeKeyByFriendlyName( $name, $user ) {
		$keys = $user->getKeys();
		$newKeys = array_filter( $keys, function ( $key ) use ( $name ) {
			if ( !( $key instanceof WebAuthnKey ) ) {
				return false;
			}
			return $key->getFriendlyName() !== $name;
		} );

		$user->setKeys( $newKeys );
		return $newKeys !== $keys;
	}

	/**
	 * @return WebAuthnConfig
	 */
	public function getConfig() {
		return new WebAuthnConfig();
	}

	/**
	 * @inheritDoc
	 */
	public function getDescriptionMessage() {
		return wfMessage( 'webauthn-module-description' );
	}

	/**
	 * Message that will be shown when user is disabling the module,
	 * to warn the user of token/data loss
	 *
	 * @return Message|null
	 */
	public function getDisableWarningMessage() {
		return null;
	}
}
