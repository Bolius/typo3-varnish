<?php

namespace Snowflake\Varnish\Controller;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2012  Andri Steiner  <team@snowflakeops.ch>
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
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
use Snowflake\Varnish\Utility\VarnishGeneralUtility;
use Snowflake\Varnish\Utility\VarnishHttpUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;

/**
 * This class contains controls communication between TYPO3 and Varnish
 *
 * @author    Andri Steiner  <team@snowflakeops.ch>
 * @package    TYPO3
 * @subpackage    tx_varnish
 */
class VarnishController
{


    /**
     * List of Varnish hostnames
     *
     * @var array
     */
    protected $instanceHostnames = array ();


    /**
     * Load Configuration and assing default values
     *
     * @throws \UnexpectedValueException
     */
    public function __construct()
    {
        // assign Varnish daemon hostnames
        $this->instanceHostnames = VarnishGeneralUtility::getProperty('instanceHostnames');
        if (empty($this->instanceHostnames)) {
            $this->instanceHostnames = GeneralUtility::getIndpEnv('HTTP_HOST');
        }

        // convert Comma separated List into a Array
        $this->instanceHostnames = GeneralUtility::trimExplode(',', $this->instanceHostnames, true);
    }


    /**
     * ClearCache
     * Executed by the clearCachePostProc Hook
     *
     * @param string|int $cacheCmd Cache Command, see Description in t3lib_tcemain
     *
     * @return    void
     *
     * @throws \InvalidArgumentException
     */
    public function clearCache($cacheCmd)
    {
        // if cacheCmd is -1, were in a draft workspace and skip Varnish clearing all together
        if ($cacheCmd === -1) {
            return;
        }

        // move this elsewhere ?
        $GLOBALS['TSFE'] = GeneralUtility::makeInstance('TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController',
            $GLOBALS['TYPO3_CONF_VARS'], 1, 0);
        $GLOBALS['TSFE']->sys_page = GeneralUtility::makeInstance('TYPO3\CMS\Frontend\Page\PageRepository');
        $rootLine = $GLOBALS['TSFE']->sys_page->getRootLine(1);
        $GLOBALS['TSFE']->tmpl = GeneralUtility::makeInstance('TYPO3\CMS\Core\TypoScript\ExtendedTemplateService');
        $GLOBALS['TSFE']->tmpl->init();
        $GLOBALS['TSFE']->tmpl->runThroughTemplates($rootLine);
        $GLOBALS['TSFE']->tmpl->tt_track = 0;
        $GLOBALS['TSFE']->tmpl->generateConfig();
        $GLOBALS['TSFE']->tmpl->loaded = 1;
        $GLOBALS['TSFE']->config['config']['tx_realurl_enable'] = 1;
        $GLOBALS['TSFE']->config['mainScript'] = 'index.php';
        if ($temp = $GLOBALS['TSFE']->getDomainDataForPid($cacheCmd)) {
            $host = $temp['domainName'];
        }
        // ----

        // Log debug infos
        VarnishGeneralUtility::devLog('clearCache', array ('cacheCmd' => $cacheCmd));

        // if cacheCmd is a single Page, issue BAN Command on this pid
        // all other Commands ("page", "all") led to a BAN of the whole Cache
        $cacheCmd = (int)$cacheCmd;
        $command = array (
            // used for making a Varnish ban
            $cacheCmd > 0 ? 'Varnish-Ban-TYPO3-Pid: ' . $cacheCmd : 'Varnish-Ban-All: 1',
            'Varnish-Ban-TYPO3-Sitename: ' . VarnishGeneralUtility::getSitename(),

            // used for making a Varnish purge
            'Host: ' . $host,
            'X-Forwarded-Proto: https',
        );
        $method = VarnishGeneralUtility::getProperty('banRequestMethod') ?: 'BAN';

        $contentObjectRenderer = GeneralUtility::makeInstance(ContentObjectRenderer::class);
        $path = $contentObjectRenderer->typoLink_URL([
            'parameter' => $cacheCmd,
        ]);
        // issue command on every Varnish Server
        /** @var $varnishHttp VarnishHttpUtility */
        $varnishHttp = GeneralUtility::makeInstance(VarnishHttpUtility::class);
        foreach ($this->instanceHostnames as $currentHost) {
            $varnishHttp::addCommand($method, $currentHost . '/' . $path, $command);
        }
    }
}
