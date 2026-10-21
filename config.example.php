<?php
/**
 * Copy to config.php (gitignored) when the host can't set environment
 * variables. Environment variables are used for anything left out.
 *
 * @package ExactSocial\Relay
 */

return [
	// Required.
	'FB_APP_ID'              => '',
	'FB_APP_SECRET'          => '',
	'FB_CONFIG_ID'           => '', // "Exact Social - Facebook Pages"
	'RELAY_URL'              => 'https://connect.exactwp.com',
	'RELAY_KEY'              => '', // openssl rand -base64 32

	// Each switches on its network or set.
	'FB_CONFIG_ID_INSTAGRAM' => '', // "Exact Social - FB + Instagram"
	'FB_CONFIG_ID_INBOX'     => '', // "Exact Social - Inbox"
	'INSTAGRAM_APP_ID'       => '',
	'INSTAGRAM_APP_SECRET'   => '',
	'THREADS_APP_ID'         => '',
	'THREADS_APP_SECRET'     => '',
];
