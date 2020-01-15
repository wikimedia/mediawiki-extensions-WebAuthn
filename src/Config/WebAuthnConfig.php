<?php

namespace MediaWiki\Extension\WebAuthn\Config;

use GlobalVarConfig;
use HashConfig;
use MultiConfig;

class WebAuthnConfig extends MultiConfig {
	public function __construct() {
		parent::__construct( [
			new GlobalVarConfig( 'wgWebAuthn_' ),
			new HashConfig( [
				// maximal number of keys user can have registered
				'maxKeysPerUser' => 5
			] )
		] );
	}
}
