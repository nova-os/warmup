<?php

$EM_CONF[$_EXTKEY] = array(
	'title' => 'Cache Warmup',
	'description' => 'Allows to warmup caches via the command line interface',
	'category' => 'be',
	'author' => 'b13 GmbH',
	'author_email' => 'typo3@b13.com',
	'state' => 'stable',
	'version' => '1.1.1',
	'constraints' => array(
		'depends' => array(
			'typo3' => '8.7.0-10.4.99',
		),
		'conflicts' => array(
		),
		'suggests' => array(
		),
	),
);
