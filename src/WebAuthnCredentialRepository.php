<?php

namespace MediaWiki\Extension\WebAuthn;

use Base64Url\Base64Url;
use MediaWiki\Config\ConfigException;
use MediaWiki\Extension\OATHAuth\OATHUser;
use MediaWiki\Extension\OATHAuth\OATHUserRepository;
use MediaWiki\Extension\WebAuthn\Key\WebAuthnKey;
use MediaWiki\Extension\WebAuthn\Module\WebAuthn;
use MediaWiki\MediaWikiServices;
use MWException;
use Webauthn\PublicKeyCredentialSource;
use Webauthn\PublicKeyCredentialSourceRepository;
use Webauthn\PublicKeyCredentialUserEntity;

class WebAuthnCredentialRepository implements PublicKeyCredentialSourceRepository {

	/** @var WebAuthnKey[] */
	protected $keys = [];

	/**
	 * @var bool
	 */
	protected $loaded = false;

	protected OATHUser $oauthUser;

	public function __construct( OATHUser $user ) {
		$this->oauthUser = $user;
	}

	/**
	 * @param bool $lc Whether to return the names in lowercase form
	 * @return array
	 */
	public function getFriendlyNames( $lc = false ) {
		$this->load();
		$friendlyNames = [];
		foreach ( $this->keys as $key ) {
			$friendlyName = $key->getFriendlyName();
			if ( $lc ) {
				$friendlyName = strtolower( $friendlyName );
			}
			$friendlyNames[] = $friendlyName;
		}
		return $friendlyNames;
	}

	public function findOneByCredentialId(
		string $publicKeyCredentialId
	): ?PublicKeyCredentialSource {
		$this->load();
		foreach ( $this->keys as $key ) {
			if ( $key->getAttestedCredentialData()->getCredentialId() === $publicKeyCredentialId ) {
				return $this->credentialSourceFromKey( $key );
			}
		}

		return null;
	}

	/**
	 * @param PublicKeyCredentialUserEntity $publicKeyCredentialUserEntity
	 * @return PublicKeyCredentialSource[]
	 */
	public function findAllForUserEntity(
		PublicKeyCredentialUserEntity $publicKeyCredentialUserEntity
	): array {
		$res = [];
		foreach ( $this->keys as $key ) {
			if ( $key->getUserHandle() === $publicKeyCredentialUserEntity->getId() ) {
				$res[] = $this->credentialSourceFromKey( $key );
			}
		}

		return $res;
	}

	/**
	 * @param PublicKeyCredentialSource $publicKeyCredentialSource
	 * @throws MWException
	 * @throws ConfigException
	 */
	public function saveCredentialSource(
		PublicKeyCredentialSource $publicKeyCredentialSource
	): void {
		$this->updateCounterFor(
			$publicKeyCredentialSource->getPublicKeyCredentialId(),
			$publicKeyCredentialSource->getCounter()
		);
	}

	/**
	 * @param WebAuthnKey $key
	 * @return PublicKeyCredentialSource
	 */
	private function credentialSourceFromKey( WebAuthnKey $key ) {
		return PublicKeyCredentialSource::createFromArray( [
			'userHandle' => Base64Url::encode( $key->getUserHandle() ),
			'aaguid' => $key->getAttestedCredentialData()->getAaguid()->toString(),
			'friendlyName' => $key->getFriendlyName(),
			'publicKeyCredentialId' => Base64Url::encode(
				$key->getAttestedCredentialData()->getCredentialId()
			),
			'credentialPublicKey' => Base64Url::encode(
				(string)$key->getAttestedCredentialData()->getCredentialPublicKey()
			),
			'counter' => $key->getSignCounter(),
			'userMWId' => $this->oauthUser->getUser()->getId(),
			'type' => $key->getType(),
			'transports' => $key->getTransports(),
			'attestationType' => $key->getAttestationType(),
			'trustPath' => $key->getTrustPath()->jsonSerialize()
		] );
	}

	/**
	 * Loads keys for user
	 */
	private function load() {
		if ( !$this->oauthUser->getModule() instanceof WebAuthn ) {
			// User does not have WebAuthn enabled - for safety, as it should be checked
			// long before it comes to this
			return;
		}

		if ( $this->loaded ) {
			return;
		}
		$keys = $this->oauthUser->getKeys();

		/** @var WebAuthnKey $key */
		foreach ( $keys as $key ) {
			if ( !$key instanceof WebAuthnKey ) {
				// Sense-check
				continue;
			}
			$this->keys[] = $key;
		}

		$this->loaded = true;
	}

	/**
	 * Set a new sign counter-value for the credential
	 *
	 * @param string $credentialId
	 * @param int $newCounter
	 * @throws MWException
	 */
	private function updateCounterFor( string $credentialId, int $newCounter ): void {
		$this->load();

		foreach ( $this->keys as $key ) {
			if ( $key->getAttestedCredentialData()->getCredentialId() === $credentialId ) {
				$key->setSignCounter( $newCounter );
			}
		}
		$this->oauthUser->setKeys( $this->keys );
		/** @var OATHUserRepository $repo */
		$repo = MediaWikiServices::getInstance()->getService( 'OATHUserRepository' );
		$repo->persist( $this->oauthUser );
	}
}
