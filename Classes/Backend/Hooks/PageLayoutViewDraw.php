<?php
namespace Portrino\PxShopware\Backend\Hooks;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2016 Andre Wuttig <wuttig@portrino.de>, portrino GmbH
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

use Portrino\PxShopware\Service\Shopware\AbstractShopwareApiClientInterface;
use Portrino\PxShopware\Service\Shopware\ArticleClient;
use Portrino\PxShopware\Service\Shopware\CategoryClient;
use Portrino\PxShopware\Service\Shopware\LanguageToShopwareMappingService;
use Portrino\PxShopware\Service\Shopware\VariantClient;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Backend\View\PageLayoutView;
use TYPO3\CMS\Backend\View\PageLayoutViewDrawItemHookInterface;
use TYPO3\CMS\Core\Database\DatabaseConnection;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\BackendConfigurationManager;
use TYPO3\CMS\Extbase\Object\ObjectManager;
use TYPO3\CMS\Extbase\Object\ObjectManagerInterface;
use TYPO3\CMS\Extbase\Persistence\ObjectStorage;
use TYPO3\CMS\Extbase\Service\FlexFormService;
use TYPO3\CMS\Extbase\Service\TypoScriptService;
use TYPO3\CMS\Fluid\View\StandaloneView;
use TYPO3\CMS\Lang\LanguageService;

/**
 * Class PageLayoutViewDraw
 *
 * @package Portrino\PxShopware\Backend\Hooks
 */
class PageLayoutViewDraw implements PageLayoutViewDrawItemHookInterface
{

    /**
     * @var ObjectManagerInterface
     */
    protected $objectManager;

    /**
     * @var BackendConfigurationManager
     */
    protected $configurationManager;

    /**
     * @var array
     */
    protected $settings = [];

    /**
     * @var TypoScriptService
     */
    protected $typoScriptService;

    /**
     * @var LanguageToShopwareMappingService
     */
    protected $languageToShopMappingService;

    /**
     * @var FlexFormService
     */
    protected $flexFormService;

    /**
     * @var AbstractShopwareApiClientInterface
     */
    protected $shopwareClient;

    /**
     * @var StandaloneView
     */
    protected $view;

    /**
     * @var DatabaseConnection
     */
    protected $databaseConnection;

    /**
     * @var LanguageService
     */
    protected $languageService;

    /**
     * @var string
     */
    protected $extensionKey = 'px_shopware';

    /**
     * Pi1PageLayoutViewDraw constructor.
     *
     */
    public function __construct()
    {
        $this->objectManager = GeneralUtility::makeInstance(ObjectManager::class);
        $this->configurationManager = $this->objectManager->get(BackendConfigurationManager::class);
        $this->settings = $this->configurationManager->getConfiguration('PxShopware');
        $this->typoScriptService = $this->objectManager->get(TypoScriptService::class);
        $this->flexFormService = $this->objectManager->get(FlexFormService::class);
        $this->articleClient = $this->objectManager->get(ArticleClient::class);
        $this->categoryClient = $this->objectManager->get(CategoryClient::class);
        $this->languageToShopMappingService = $this->objectManager->get(LanguageToShopwareMappingService::class);
        $this->databaseConnection = $this->getDatabase();
        $this->languageService = $this->getLanguageService();

        $this->view = $this->objectManager->get(StandaloneView::class);
    }

    /**
     * @param string $CType
     * @throws \TYPO3\CMS\Fluid\View\Exception\InvalidTemplateResourceException
     */
    protected function initializeView($CType)
    {
        $templateName = str_replace('Pxshopware', '', GeneralUtility::underscoredToUpperCamelCase($CType));

        $this->view->setTemplateRootPaths(
            [0 => 'EXT:' . $this->extensionKey . '/Resources/Private/Templates/Backend/PageLayoutViewDrawItem/']
        );
        $this->view->setTemplate($templateName);
    }

    /**
     * @param string $CType
     * @param array $switchableControllerActions
     */
    protected function initializeShopwareClient($CType, $switchableControllerActions)
    {
        switch ($CType) {
            case 'pxshopware_pi1':
                switch (current($switchableControllerActions)) {
                    case 'Article->list':
                        $this->shopwareClient = $this->objectManager->get(ArticleClient::class);
                        break;
                    case 'Article->listByCategories':
                        $this->shopwareClient = $this->objectManager->get(CategoryClient::class);
                        break;
                    case 'Variant->list':
                        $this->shopwareClient = $this->objectManager->get(VariantClient::class);
                        break;
                }
                break;
            case 'pxshopware_pi2':
                $this->shopwareClient = $this->objectManager->get(CategoryClient::class);
                break;
        }
    }

    /**
     * Preprocesses the preview rendering of a content element.
     *
     * @param PageLayoutView $parentObject Calling parent object
     * @param boolean $drawItem Whether to draw the item using the default functionalities
     * @param string $headerContent Header content
     * @param string $itemContent Item content
     * @param array $row Record row of tt_content
     *
     * @return void
     * @throws \TYPO3\CMS\Fluid\View\Exception\InvalidTemplateResourceException
     */
    public function preProcess(
        PageLayoutView &$parentObject,
        &$drawItem,
        &$headerContent,
        &$itemContent,
        array &$row
    ) {
        /** @var string $CType */
        $CType = $row['CType'];

        if (\in_array($CType, ['pxshopware_pi1', 'pxshopware_pi2'], true) === false) {
            return;
        }

        /** @var array $flexFormConfiguration */
        $flexFormConfiguration = $this->flexFormService->convertFlexFormContentToArray($row['pi_flexform']);
        $switchableControllerActions = [];
        if (array_key_exists('switchableControllerActions', $flexFormConfiguration)) {
            $switchableControllerActions = GeneralUtility::trimExplode(
                ';',
                $flexFormConfiguration['switchableControllerActions'],
                true
            );
        }

        $this->initializeView($CType);
        $this->initializeShopwareClient($CType, $switchableControllerActions);

        /** @var ObjectStorage $selectedItems */
        $selectedItems = new ObjectStorage();
        switch (current($switchableControllerActions)) {
            case 'Article->listByCategories':
                $selectedItemsArray = isset($flexFormConfiguration['settings']['categories']) ?
                    GeneralUtility::intExplode(',', $flexFormConfiguration['settings']['categories'], true) :
                    [];
                break;
            case 'Variant->list':
                $selectedItemsArray = isset($flexFormConfiguration['settings']['variants']) ?
                    GeneralUtility::intExplode(',', $flexFormConfiguration['settings']['variants'], true) :
                    [];
                break;
            default:
                $selectedItemsArray = isset($flexFormConfiguration['settings']['items']) ?
                    GeneralUtility::intExplode(',', $flexFormConfiguration['settings']['items'], true) :
                    [];

        }

        foreach ($selectedItemsArray as $item) {
            $language = $this->languageToShopMappingService->getShopIdBySysLanguageUid($row['sys_language_uid']);
            /** @var ItemEntryInterface $selectedItem */
            $selectedItem = $this->shopwareClient->findById($item, true, ['language' => $language]);

            if ($selectedItem) {
                if (current($switchableControllerActions) === 'Variant->list') {
                    $selectedItem = $selectedItem->getArticle();
                }
                $selectedItems->attach($selectedItem);
            }
        }

        $this->view->assign('selectedItems', $selectedItems);
        $TCEFORM_TSconfig = BackendUtility::getTCEFORM_TSconfig('tt_content', $row);
        $TCEFORM_TSconfig = $this->typoScriptService->convertTypoScriptArrayToPlainArray($TCEFORM_TSconfig['pi_flexform']);

        $templateConfigurations = $TCEFORM_TSconfig[$CType]['sDEF']['settings.template']['addItems'];

        if (isset($flexFormConfiguration['settings']['template'])
            && array_key_exists($flexFormConfiguration['settings']['template'], $templateConfigurations)
        ) {
            $templateLLL = $TCEFORM_TSconfig[$CType]['sDEF']['settings.template']['addItems'][$flexFormConfiguration['settings']['template']];
            $template = $this->languageService->sL($templateLLL);
        } else {
            $template = $this->languageService->sL('LLL:EXT:px_shopware/Resources/Private/Language/locallang_db.xlf:tt_content.pi_flexform.' . $CType . '.settings.template.not_defined');
        }

        $header = $this->languageService->sL('LLL:EXT:px_shopware/Resources/Private/Language/locallang_db.xlf:tt_content.CType.' . $CType);

        $this->view->assign('header', $header);
        $this->view->assign('template', $template);
        $this->view->assign('row', $row);

        $itemContent = $this->view->render();
        $drawItem = false;
    }

    /**
     * @return DatabaseConnection
     */
    protected function getDatabase()
    {
        return $GLOBALS['TYPO3_DB'];
    }

    /**
     * @return LanguageService
     */
    protected function getLanguageService()
    {
        return $GLOBALS['LANG'];
    }
}
