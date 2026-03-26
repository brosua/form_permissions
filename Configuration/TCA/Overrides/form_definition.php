<?php

declare(strict_types=1);

defined('TYPO3') || die();

// Allow form_definition records to be stored on any page (not only root/pid=0).
$GLOBALS['TCA']['form_definition']['ctrl']['rootLevel'] = -1;
