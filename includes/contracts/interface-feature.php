<?php
/**
 * Feature contract for isolated site-specific features.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface WMP_Site_Feature {
	public function init();
}
