<?php

namespace MediaWiki\Extension\WebAuthn\HTMLForm;

use ConfigException;
use MediaWiki\Extension\OATHAuth\HTMLForm\IManageForm;
use MediaWiki\Extension\OATHAuth\HTMLForm\OATHAuthOOUIHTMLForm;
use MediaWiki\Extension\OATHAuth\IModule;
use MediaWiki\Extension\OATHAuth\OATHUser;
use MediaWiki\Extension\OATHAuth\OATHUserRepository;
use MediaWiki\Extension\WebAuthn\Authenticator;
use MediaWiki\Extension\WebAuthn\HTMLField\RegisteredKeyLayout;
use MediaWiki\Extension\WebAuthn\Key\WebAuthnKey;
use MediaWiki\Extension\WebAuthn\Module\WebAuthn;
use MediaWiki\MediaWikiServices;
use MWException;
use OOUI\ButtonWidget;
use SpecialPage;

class WebAuthnManageForm extends OATHAuthOOUIHTMLForm implements IManageForm {

	/**
	 * @var bool
	 */
	protected $panelPadded = false;

	/**
	 * @var bool
	 */
	protected $panelFramed = false;

	/**
	 * @var WebAuthn
	 */
	protected $module;

	/**
	 * @inheritDoc
	 */
	public function __construct( OATHUser $oathUser, OATHUserRepository $oathRepo, IModule $module ) {
		parent::__construct( $oathUser, $oathRepo, $module );

		$this->setId( 'webauthn-manage-form' );
		$this->suppressDefaultSubmit();
	}

	/**
	 * @inheritDoc
	 */
	public function getHTML( $submitResult ) {
		$this->getOutput()->addModules( 'ext.webauthn.manage' );
		return parent::getHTML( $submitResult );
	}

	/**
	 * @return ButtonWidget|string
	 * @throws ConfigException
	 * @throws MWException
	 */
	public function getButtons() {
		$moduleConfig = $this->module->getConfig()->get( 'maxKeysPerUser' );
		if ( count( $this->oathUser->getKeys() ) >= (int)$moduleConfig ) {
			return '';
		}
		return new ButtonWidget( [
			'id' => 'button_add_key',
			'flags' => [ 'progressive', 'primary' ],
			'disabled' => true,
			'label' => wfMessage( 'webauthn-ui-add-key' )->plain(),
			'href' => SpecialPage::getTitleFor( 'OATHManage' )->getLocalURL( [
				'module' => 'webauthn',
				'action' => WebAuthn::ACTION_ADD_KEY
			] ),
			'infusable' => true
		] );
	}

	/**
	 * Add content to output when operation was successful
	 */
	public function onSuccess() {
		$this->getOutput()->redirect(
			SpecialPage::getTitleFor( 'OATHManage' )->getLocalURL()
		);
	}

	/**
	 * @param array $formData
	 * @return array|bool
	 * @throws ConfigException
	 * @throws MWException
	 */
	public function onSubmit( array $formData ) {
		if ( !isset( $formData['credential'] ) ) {
			return [ 'oathauth-failedtovalidateoath' ];
		}

		if ( !$this->authenticate( $formData['credential'] ) ) {
			return [ 'oathauth-failedtovalidateoath' ];
		}
		if ( isset( $formData['remove_key'] ) ) {
			return $this->removeKey( $formData['remove_key'] );
		}
		return true;
	}

	/**
	 * @return array
	 * @throws ConfigException
	 * @throws MWException
	 */
	protected function getDescriptors() {
		/** @var OATHUserRepository $userRepo */
		$userRepo = MediaWikiServices::getInstance()->getService( 'OATHUserRepository' );
		/** @var OATHUser $oathUser */
		$oathUser = $userRepo->findByUser( $this->getUser() );
		/** @var WebAuthnKey[] $keys */
		$keys = $oathUser->getKeys();

		$registeredKeys = [];
		foreach ( $keys as $idx => $key ) {
			$registeredKeys["reg_key_$idx"] = [
				'type' => 'null',
				'default' => [
					'name' => $key->getFriendlyName(),
					'signCount' => $key->getSignCounter()
				],
				'raw' => true,
				'class' => RegisteredKeyLayout::class,
				'section' => 'webauthn-registered-keys-section-name'
			];
		}

		return $registeredKeys + [
			'edit_key' => [
				'type' => 'hidden',
				'name' => 'edit_key'
			],
			'remove_key' => [
				'type' => 'hidden',
				'name' => 'remove_key'
			],
			'credential' => [
				'type' => 'hidden',
				'name' => 'credential'
			]
		];
	}

	/**
	 * @param string $key Friendly name
	 * @return array|bool
	 * @throws MWException
	 * @throws ConfigException
	 */
	private function removeKey( $key ) {
		$removed = $this->module->removeKeyByFriendlyName( $key, $this->oathUser );
		if ( !$removed ) {
			return [ 'webauthn-error-cannot-remove-key' ];
		}

		if ( $this->oathUser->getFirstKey() === null ) {
			// User removed all keys
			$this->oathRepo->remove( $this->oathUser, $this->getRequest()->getIP() );
		} else {
			$this->oathRepo->persist( $this->oathUser, $this->getRequest()->getIP() );
		}
		return true;
	}

	/**
	 * @param array $credential
	 * @return bool
	 * @throws ConfigException
	 */
	private function authenticate( $credential ) {
		$verificationData = [
			'credential' => $credential
		];
		$authenticator = Authenticator::factory( $this->getUser(), $this->getRequest() );
		if ( !$authenticator->isEnabled() ) {
			return false;
		}
		$authenticationResult = $authenticator->continueAuthentication( $verificationData );
		if ( $authenticationResult->isGood() ) {
			return true;
		}
		return false;
	}
}
