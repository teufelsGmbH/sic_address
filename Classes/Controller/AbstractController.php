<?php

namespace SICOR\SicAddress\Controller;

/***************************************************************
 *
 *  Copyright notice
 *
 *  (c) 2020 SICOR DEVTEAM <dev@sicor-kdl.net>, Sicor KDL GmbH
 *
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use TYPO3\CMS\Core\Messaging\AbstractMessage;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Mvc\View\ViewInterface;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

class AbstractController extends ActionController
{
    protected function translate(string $key, string $extension = 'sic_address'): string
    {
        return LocalizationUtility::translate($key, $extension);
    }

    /**
     * @param ViewInterface $view
     */
    public function initializeView(ViewInterface $view): void
    {
        parent::initializeView($view);

        if (!in_array($this->request->getControllerActionName(), array('create', 'update'))) {
            $updateOrFirstInstall = is_file(ExtensionManagementUtility::extPath("sic_address") . 'PLEASE_GENERATE');
            if ($updateOrFirstInstall && $this->domainPropertyRepository && $this->domainPropertyRepository->countAll()) {
                $this->addFlashMessage(
                    $this->translate('label_update_message'),
                    $this->translate('label_update_title'),
                    AbstractMessage::ERROR
                );
            }
        }
    }
}
