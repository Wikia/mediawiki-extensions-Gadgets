<?php

use Fandom\ContentReview\Services\ContentReviewTestModeService;
use Fandom\ContentReview\Services\GadgetModuleContentService;
use Fandom\ContentReview\Services\GadgetModuleTitleInfoService;
use Fandom\ContentReview\Storage\ContentReviewStorage;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;

/**
 * Class representing a list of resources for one gadget, basically a wrapper
 * around the Gadget class.
 */
class GadgetResourceLoaderModule extends ResourceLoaderWikiModule {
	/**
	 * @var string
	 */
	private $id;

	/**
	 * @var Gadget
	 */
	private $gadget;

	/**
	 * @param array $options
	 */
	public function __construct( array $options ) {
		$this->id = $options['id'];
	}

	/**
	 * @return Gadget instance this module is about
	 */
	private function getGadget() {
		if ( !$this->gadget ) {
			try {
				$this->gadget = GadgetRepo::singleton()->getGadget( $this->id );
			} catch ( InvalidArgumentException $e ) {
				// Fallback to a placeholder object...
				$this->gadget = Gadget::newEmptyGadget( $this->id );
			}
		}

		return $this->gadget;
	}

	/**
	 * Overrides the function from ResourceLoaderWikiModule class
	 * @param ResourceLoaderContext $context
	 * @return array
	 */
	protected function getPages( ResourceLoaderContext $context ) {
		$gadget = $this->getGadget();
		$pages = [];

		foreach ( $gadget->getStyles() as $style ) {
			$pages[$style] = [ 'type' => 'style' ];
		}

		if ( $gadget->supportsResourceLoader() ) {
			foreach ( $gadget->getScripts() as $script ) {
				$pages[$script] = [ 'type' => 'script' ];
			}
		}

		return $pages;
	}

	/**
	 * Overrides ResourceLoaderModule::getDependencies()
	 * @param ResourceLoaderContext|null $context
	 * @return string[] Names of resources this module depends on
	 */
	public function getDependencies( ResourceLoaderContext $context = null ) {
		return $this->getGadget()->getDependencies();
	}

	/**
	 * Overrides ResourceLoaderWikiModule::getType()
	 * @return string ResourceLoaderModule::LOAD_STYLES or ResourceLoaderModule::LOAD_GENERAL
	 */
	public function getType() {
		return $this->getGadget()->getType() === 'styles'
			? ResourceLoaderModule::LOAD_STYLES
			: ResourceLoaderModule::LOAD_GENERAL;
	}

	public function getMessages() {
		return $this->getGadget()->getMessages();
	}

	public function getTargets() {
		return $this->getGadget()->getTargets();
	}

	public function getGroup() {
		// Fandom change - IW-4592 - Gadgets ContentReview integration
		// Ensure the user sees their own changes when ContentReview is active and in test mode
		if ( ExtensionRegistry::getInstance()->isLoaded( 'ContentReview' ) && $this->isContentReviewDisabledOrTestMode() ) {
			return 'user';
		}

		return 'site';
	}

	/**
	 * Fandom change - IW-4592 - Gadgets ContentReview integration
	 *
	 * Get the content for a given page used by this gadget.
	 * If ContentReview is enabled and this is a JS page, the content of the last approved revision will be returned.
	 * Otherwise, the content of the latest revision will be used.
	 * Redirects are only followed if ContentReview is not used.
	 *
	 * @param Title $title
	 * @param ResourceLoaderContext|null $context
	 * @param int|null $maxRedirects
	 * @return Content|null
	 * @throws ConfigException
	 */
	protected function getContentObj( Title $title, ResourceLoaderContext $context = null, $maxRedirects = null ) {
		// Fetch unreviewed content if the ContentReview system is not enabled for this wiki or it's in test mode
		if ( $this->isContentReviewDisabledOrTestMode( $context->getUserObj() ) ) {
			return parent::getContentObj( $title, $context, $maxRedirects );
		}

		return $this->getGadgetModuleContentService()->getContentObj( $title );
	}

	/**
	 * Fandom change - IW-4592 - Gadgets ContentReview integration
	 * Fetch metadata for the pages that compose this module, for freshness checks.
	 * @param ResourceLoaderContext $context
	 * @return array|mixed
	 * @throws ConfigException
	 */
	protected function getTitleInfo( ResourceLoaderContext $context ) {
		$pageNames = array_keys( $this->getPages( $context ) );
		sort( $pageNames );
		$batchKey = implode( '|', $pageNames );

		if ( !isset( $this->titleInfo[$batchKey] ) ) {
			$this->titleInfo[$batchKey] = $this->loadTitleInfo( $context, $pageNames, __METHOD__ );
		}

		return $this->titleInfo[$batchKey];
	}

	/**
	 * Fandom change - IW-4592 - Gadgets ContentReview integration
	 * Disable getDB() so that ResourceLoaderWikiModule::preloadTitleInfo() does not load title metadata for the
	 * wrong revision (ignoring JS Review)
	 *
	 * @see ResourceLoaderWikiModule::preloadTitleInfo()
	 * @return \Wikimedia\Rdbms\IDatabase|null
	 */
	protected function getDB() {
		return null;
	}

	/**
	 * Fandom change - IW-4592 - Gadgets ContentReview integration
	 * Load page revision metadata for freshness checks.
	 * If ContentReview is active and not in test mode, use the metadata of the latest reviewed revisions for JS pages.
	 *
	 * @param ResourceLoaderContext $context
	 * @param array $pages
	 * @param string $fname
	 * @return array - map of page names to corresponding metadata
	 * @throws ConfigException
	 * @see ResourceLoaderWikiModule::fetchTitleInfo()
	 *
	 */
	protected function loadTitleInfo( ResourceLoaderContext $context, array $pages, $fname = __METHOD__ ) {
		$lb = MediaWikiServices::getInstance()->getDBLoadBalancer();
		$dbr = $lb->getConnectionRef( DB_REPLICA );

		if ( $this->isContentReviewDisabledOrTestMode( $context->getUserObj() ) ) {
			return parent::fetchTitleInfo( $dbr, $pages, $fname );
		}

		return $this->getGadgetModuleTitleInfoService()->getTitleInfo( $pages );
	}

	/**
	 * Fandom change - IW-4592 - Gadgets ContentReview integration
	 * Determine whether ContentReview is disabled or if its test mode is enabled.
	 *
	 * @param User|null $user - the request user to check test mode status for
	 * @return bool
	 */
	private function isContentReviewDisabledOrTestMode( User $user = null ): bool {
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'ContentReview' ) ) {
			return true;
		}

		$user = defined( 'MW_NO_SESSION' ) ? $user : RequestContext::getMain()->getUser();

		if ( $user && $user->isLoggedIn() ) {
			$services = MediaWikiServices::getInstance();

			return $services->getService( ContentReviewTestModeService::class )->isInTestMode( $user->getId() );
		}

		return false;
	}

	/**
	 * Fandom change - IW-4592 - Gadgets ContentReview integration
	 * @return GadgetModuleTitleInfoService
	 * @throws ConfigException
	 */
	private function getGadgetModuleTitleInfoService(): GadgetModuleTitleInfoService {
		$services = MediaWikiServices::getInstance();

		return new GadgetModuleTitleInfoService(
			$services->getService( ContentReviewStorage::class ),
			$services->getSlotRoleRegistry()->getRoleHandler( SlotRecord::MAIN ),
			$services->getDBLoadBalancer(),
			$services->getMainConfig()->get( 'CityId' )
		);
	}

	/**
	 * Fandom change - IW-4592 - Gadgets ContentReview integration
	 * @return GadgetModuleContentService
	 * @throws ConfigException
	 */
	private function getGadgetModuleContentService(): GadgetModuleContentService {
		$services = MediaWikiServices::getInstance();

		return new GadgetModuleContentService(
			$services->getRevisionLookup(),
			$services->getService( ContentReviewStorage::class ),
			$services->getMainConfig()->get( 'CityId' ),
			$services->getSlotRoleRegistry()->getRoleHandler( SlotRecord::MAIN )
		);
	}
}
