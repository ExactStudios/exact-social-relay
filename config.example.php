<?php
/**
 * Copy to config.php (gitignored) when the host can't set environment
 * variables. Environment variables are used for anything left out.
 *
 * @package ExactSocial\Relay
 */

return [
	'FB_APP_ID'     => '',
	'FB_APP_SECRET' => '',
	'FB_CONFIG_ID'  => '',
	'RELAY_URL'     => 'https://connect.exactwp.com',
	'RELAY_KEY'     => '', // php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
];
