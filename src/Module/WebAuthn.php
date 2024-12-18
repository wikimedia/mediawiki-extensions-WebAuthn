<?php

namespace MediaWiki\Extension\WebAuthn\Module;

use MediaWiki\Context\IContextSource;
use MediaWiki\Context\RequestContext;
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
use MediaWiki\Message\Message;

class WebAuthn implements IModule {
	/**
	 * Custom action for the manage form
	 */
	public const ACTION_ADD_KEY = 'addkey';

	public static function factory() {
		return new static();
	}

	/** @inheritDoc */
	public function getName() {
		return 'webauthn';
	}

	/** @inheritDoc */
	public function getDisplayName() {
		return wfMessage( 'webauthn-module-label' );
	}

	/**
	 * @param array $data
	 * @return WebAuthnKey
	 */
	public function newKey( array $data = [] ) {
		if ( !$data ) {
			return WebAuthnKey::newKey();
		}
		return WebAuthnKey::newFromData( $data );
	}

	/**
	 * @return WebAuthnSecondaryAuthenticationProvider
	 */
	public function getSecondaryAuthProvider() {
		return new WebAuthnSecondaryAuthenticationProvider();
	}

	/** @inheritDoc */
	public function isEnabled( OATHUser $user ) {
		foreach ( $user->getKeys() as $key ) {
			if ( $key instanceof WebAuthnKey ) {
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
	 * Returns the appropriate form for the given action.
	 * If the ability to add nenw credentials is disabled by configuration,
	 * the empty string will be returned for any action other than ACTION_DISABLE.
	 * The value null will be returned If no suitable form is found otherwise.
	 *
	 * @param string $action
	 * @param OATHUser $user
	 * @param OATHUserRepository $repo
	 * @param IContextSource|null $context optional for backwards compatibility
	 * @return IManageForm|string|null
	 */
	public function getManageForm(
		$action,
		OATHUser $user,
		OATHUserRepository $repo,
		?IContextSource $context = null
	) {
		$module = $this;
		$context = $context ?: RequestContext::getMain();
		$enabledForUser = $this->isEnabled( $user );
		if ( $action === OATHManage::ACTION_DISABLE && $enabledForUser ) {
			return new WebAuthnDisableForm( $user, $repo, $module, $context );
		}

		if ( $context->getConfig()->get( 'WebAuthnNewCredsDisabled' ) === false ) {
			if ( $action === OATHManage::ACTION_ENABLE && !$enabledForUser ) {
				return new WebAuthnAddKeyForm( $user, $repo, $module, $context );
			}
			if ( $action === static::ACTION_ADD_KEY && $enabledForUser ) {
				return new WebAuthnAddKeyForm( $user, $repo, $module, $context );
			}
			if ( $enabledForUser ) {
				return new WebAuthnManageForm( $user, $repo, $module, $context );
			}
			return null;
		} else {
			return '';
		}
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
	 * Get a single key by its name.
	 *
	 * @param string $name
	 * @param OATHUser $user
	 *
	 * @return WebAuthnKey|null
	 */
	public function getKeyByFriendlyName( string $name, OATHUser $user ): ?WebAuthnKey {
		foreach ( $user->getKeys() as $key ) {
			if ( !( $key instanceof WebAuthnKey ) ) {
				continue;
			}

			if ( $key->getFriendlyName() === $name ) {
				return $key;
			}
		}

		return null;
	}

	/**
	 * @return WebAuthnConfig
	 */
	public function getConfig() {
		return new WebAuthnConfig();
	}

	/** @inheritDoc */
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
