<?php
/**
 * CMS Pico - Integration of Pico within your files to create websites.
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2017
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\CMSPico\Service;

use Exception;
use OC\App\AppManager;
use OCA\CMSPico\AppInfo\Application;
use OCA\CMSPico\Exceptions\AssetDoesNotExistException;
use OCA\CMSPico\Exceptions\PicoRuntimeException;
use OCA\CMSPico\Exceptions\WebsiteIsPrivateException;
use OCA\CMSPico\Model\Website;
use OCA\CMSPico\Pico;
use OCP\Files\File;
use OCP\Files\IRootFolder;

class PicoService {

	const DIR_CONFIG = 'Pico/config/';
	const DIR_PLUGINS = 'Pico/plugins/';
	const DIR_THEMES = 'Pico/themes/';

	const DIR_ASSETS = 'assets/';

	private $userId;

	/** @var AppManager */
	private $appManager;

	/** @var IRootFolder */
	private $rootFolder;

	/** @var ThemesService */
	private $themesService;

	/** @var MiscService */
	private $miscService;

	/**
	 * PicoService constructor.
	 *
	 * @param string $userId
	 * @param AppManager $appManager
	 * @param IRootFolder $rootFolder
	 * @param ThemesService $themesService
	 * @param MiscService $miscService
	 */
	function __construct(
		$userId, AppManager $appManager, IRootFolder $rootFolder, ThemesService $themesService,
		MiscService $miscService
	) {
		$this->userId = $userId;
		$this->appManager = $appManager;
		$this->rootFolder = $rootFolder;
		$this->themesService = $themesService;
		$this->miscService = $miscService;
	}


	/**
	 * getContent();
	 *
	 * @param Website $website
	 *
	 * @return string
	 */
	public function getContent(Website $website) {

		if (strpos($website->getPage(), self::DIR_ASSETS) === 0) {
			return $this->getContentFromAssets(
				$website, substr($website->getPage(), strlen(self::DIR_ASSETS))
			);
		} else {
			return $this->getContentFromPico($website);
		}
	}


	/**
	 * @param Website $website
	 * @param $asset
	 *
	 * @return string
	 * @throws AssetDoesNotExistException
	 * @throws WebsiteIsPrivateException
	 */
	public function getContentFromAssets(Website $website, $asset) {
		$website->pathCantContainSpecificFolders($asset);

		try {
			$website->viewerMustHaveAccess(self::DIR_ASSETS . $asset);
			$userFolder = $this->rootFolder->getUserFolder($website->getUserId());

			/** @var File $file */
			$file = $userFolder->get($website->getPath() . self::DIR_ASSETS . $asset);
			$content = $file->getContent();

			return $content;
		} catch (WebsiteIsPrivateException $e) {
			throw $e;
		} catch (Exception $e) {
			throw new AssetDoesNotExistException("404");
		}
	}


	/**
	 * getContentFromPico();
	 *
	 * main method that will create a Pico object, feed it with settings and get the content to be
	 * displayed.
	 * We check that the Nextcloud plugin is loaded, that the content location is a valid directory.
	 * In case of a private page, we check the viewer have a read access to the source files.
	 *
	 * @param Website $website
	 *
	 * @return string
	 * @throws PicoRuntimeException
	 */
	public function getContentFromPico(Website $website) {

		$appPath = MiscService::endSlash($this->appManager->getAppPath(Application::APP_NAME));
		$pico = new Pico(
			$website->getAbsolutePath(),
			$appPath . self::DIR_CONFIG,
			$appPath . self::DIR_PLUGINS,
			$appPath . self::DIR_THEMES
		);

		$this->setupPico($pico, $website);
		try {
			$content = $pico->run();
		} catch (Exception $e) {
			throw new PicoRuntimeException($e->getMessage());
		}

		$absolutePath = $this->getAbsolutePathFromPico($pico);
		$website->contentMustBeLocal($absolutePath);

		$website->viewerMustHaveAccess($website->getRelativePath($absolutePath), $pico->getFileMeta());

		return $content;
	}


	/**
	 * @param Pico $pico
	 * @param Website $website
	 */
	private function setupPico(Pico $pico, Website $website) {
		$pico->setRequestUrl($website->getPage());

		$this->themesService->hasToBeAValidTheme($website->getTheme());

		$appBaseUrl = \OC::$WEBROOT . '/index.php/apps/' . Application::APP_NAME;
		$pico->setConfig(
			[
				'site_title'     => $website->getName(),
				'base_url'       => $appBaseUrl . '/pico/' . $website->getSite(),
				'theme'          => $website->getTheme(),
				'content_dir'    => 'content/',
				'content_ext'    => '.md',
				'nextcloud_site' => $website->getSite()
			]
		);
	}


	/**
	 * @param Pico $pico
	 *
	 * @return string
	 */
	private function getAbsolutePathFromPico(Pico $pico) {
		return $pico->getRequestFile() ?: '';
	}
}
