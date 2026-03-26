<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') || die();

ExtensionManagementUtility::addTcaSelectItem(
    'pages',
    'module',
    [
        'label' => 'LLL:EXT:form_permissions/Resources/Private/Language/locallang.xlf:pages.module.forms',
        'value' => 'forms',
        'icon' => 'apps-pagetree-folder-contains-forms',
    ]
);

$GLOBALS['TCA']['pages']['ctrl']['typeicon_classes']['contains-forms'] = 'apps-pagetree-folder-contains-forms';
