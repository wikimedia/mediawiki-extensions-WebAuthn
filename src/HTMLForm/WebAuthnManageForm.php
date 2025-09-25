<?php

namespace MediaWiki\Extension\WebAuthn\HTMLForm;

use MediaWiki\Config\ConfigException;
use MediaWiki\Context\IContextSource;
use MediaWiki\Exception\MWException;
use MediaWiki\Extension\OATHAuth\HTMLForm\OATHAuthOOUIHTMLForm;
use MediaWiki\Extension\OATHAuth\IModule;
use MediaWiki\Extension\OATHAuth\OATHAuthServices;
use MediaWiki\Extension\OATHAuth\OATHUser;
use MediaWiki\Extension\OATHAuth\OATHUserRepository;
use MediaWiki\Extension\WebAuthn\Authenticator;
use MediaWiki\Extension\WebAuthn\HTMLField\NoJsInfoField;
use MediaWiki\Extension\WebAuthn\HTMLField\RegisteredKeyLayout;
use MediaWiki\Extension\WebAuthn\Module\WebAuthn;
use MediaWiki\SpecialPage\SpecialPage;
use OOUI\ButtonWidget;

class WebAuthnManageForm extends OATHAuthOOUIHTMLForm {

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

	/** @inheritDoc */
	public function __construct(
		OATHUser $oathUser,
		OATHUserRepository $oathRepo,
		IModule $module,
		IContextSource $context
	) {
		parent::__construct( $oathUser, $oathRepo, $module, $context );

		$this->setId( 'webauthn-manage-form' );
		$this->suppressDefaultSubmit();
	}

	/** @inheritDoc */
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
		if ( count( WebAuthn::getWebAuthnKeys( $this->oathUser ) ) >= (int)$moduleConfig ) {
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
	 * Add content to output when the operation was successful
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
		if ( !isset( $formData['credential'] ) || !$this->authenticate( $formData['credential'] ) ) {
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
		$userRepo = OATHAuthServices::getInstance()->getUserRepository();
		$oathUser = $userRepo->findByUser( $this->getUser() );
		$keys = WebAuthn::getWebAuthnKeys( $oathUser );

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

		return [
			'nojs' => [
				'class' => NoJsInfoField::class,
				'section' => 'webauthn-registered-keys-section-name',
			],
		] + $registeredKeys + [
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
	 * @throws MWException
	 * @throws ConfigException
	 */
	private function removeKey( string $key ): array|bool {
		$key = $this->module->getKeyByFriendlyName( $key, $this->oathUser );
		if ( !$key ) {
			return [ 'webauthn-error-cannot-remove-key' ];
		}

		$this->oathRepo->removeKey( $this->oathUser, $key, $this->getRequest()->getIP(), true );
		return true;
	}

	/**
	 * @throws ConfigException
	 */
	private function authenticate( string $credential ): bool {
		$authenticator = Authenticator::factory( $this->getUser(), $this->getRequest() );
		if ( !$authenticator->isEnabled() ) {
			return false;
		}

		$authenticationResult = $authenticator->continueAuthentication( [
			'credential' => $credential
		] );

		return $authenticationResult->isGood();
	}
}
