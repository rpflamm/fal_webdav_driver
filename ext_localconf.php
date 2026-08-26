<?php

defined('TYPO3') or die();

$GLOBALS['TYPO3_CONF_VARS']['SYS']['fal']['registeredDrivers']['WebDav'] = [
    'class' => \RPflamm\FalWebDavDriver\Driver\WebDavDriver::class,
    'shortName' => 'WebDav',
    'label' => 'WebDAV',
    'flexFormDS' => 'FILE:EXT:fal_webdav_driver/Configuration/FlexForms/WebDavDriverFlexForm.xml',
];
