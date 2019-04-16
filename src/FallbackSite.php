<?php
/**
 * Fallback Site plugin for Craft 3.0
 * @copyright Copyright Charlie Development
 */

namespace charliedev\fallbacksite;

use Craft;
use craft\base\Plugin;
use craft\web\Application;
use craft\web\UrlManager;

use yii\base\Event;

/**
 * The main Craft plugin class.
 */
class FallbackSite extends Plugin
{
	/**
	 * @inheritdoc
	 * @see craft\base\Plugin
	 */
	public function init()
	{
		parent::init();

		// NOTE: This will wind up using Craft's `UrlManager`.
		// This means any plugins/modules that have not yet attached their event handlers to UrlManager will never
		// receive some of its events (EVENT_REGISTER_CP_URL_RULES, EVENT_REGISTER_SITE_URL_RULES). By this point
		// plugins should have added their events, but any that use the UrlManager improperly might cause trouble
		// because of the early initialization.
		Event::on(
			Application::class,
			Application::EVENT_INIT,
			function () {
				$this->checkFallbackEntry();
			}
		);
	}

	/**
	 * @inheritdoc
	 * @see craft\base\Plugin
	 */
	protected function createSettingsModel()
	{
		return new \charliedev\fallbacksite\models\Settings();
	}

	/**
	 * @inheritdoc
	 * @see craft\base\Plugin
	 */
	protected function settingsHtml()
	{
		$sites = Craft::$app->getSites()->getAllSites();
		$siteoptions = []; // A list of options, tailored to each site.

		foreach ($sites as $site) {
			$siteoptions[$site->id] = []; // Build a list for the given site.
			$siteoptions[$site->id][$site->id] = Craft::t('app', 'None'); // A site using its own ID does not fallback, and is a default 'none' option,
			foreach ($sites as $option) { // Build a list of all other sites.
				if ($option->id != $site->id) { // Do not re-add the same site to the list.
					$siteoptions[$site->id][$option->id] = Craft::t('site', $option->name);
				}
			}
		}

		return Craft::$app->getView()->renderTemplate('fallback-site/_settings', [
			'settings' => $this->getSettings(),
			'siteoptions' => $siteoptions
		]);
	}

	/**
	 * Attempts to find and render an entry from configured fallback sites if an entry hasn't been found for the visited site.
	 */
	private function checkFallbackEntry() {

		// Only run for regular web frontend requests.
		if (!Craft::$app->getRequest()->getIsConsoleRequest()
		&& Craft::$app->getRequest()->getIsSiteRequest()
		&& !Craft::$app->getRequest()->getIsLivePreview()) {
			// Determine if there is an element at the given URL, and attempt to find one using fallback sites
			// if one is not available. This may interfere with custom routes or direct templates, if one
			// happens to conflict with the potential path.
			$element = Craft::$app->getUrlManager()->getMatchedElement(); // Find the element that the current URL is going to.
			if ($element == null) { // No element found, this request may 404 normally.
				$this->renderFallbackEntry();
			}
		}
	}

	/**
	 * Attempts to find and render an entry from configured fallback sites if an entry hasn't been found for the visited site.
	 */
	private function renderFallbackEntry()
	{
		$originalsite = Craft::$app->getSites()->currentSite; // Store the original site being visited.
		$siteid = $originalsite->id; // Storage for the ID of the site currently being checked.
		$path = Craft::$app->getRequest()->getPathInfo(); // Retrieve the path being visited, used for checking for entries.
		$checked = [$originalsite->id => true]; // Mark the current site as checked.
		$sites = $this->getSettings()->sites; // Retrieve the set of sites and their fallbacks.

		// Check the fallback for each site until a valid entry is found, or the fallback is a site that has already been checked .
		while (true) {
			if (!isset($sites[$siteid])) { // No fallback (bad config, unconfigured site, etc.).
				return;
			}
			$siteid = $sites[$siteid]; // Get the ID of the fallback site for the last checked site.
			if (isset($checked[$siteid])) { // Fallback site has already checked (avoid cyclical references), give up.
				return;
			}
			$checked[$siteid] = true; // Mark this new site as checked.

			$element = Craft::$app->getElements()->getElementByUri($path, $siteid, true); // Check for an entry with the same path in the fallback site.
			if ($element) { // An element was found with the given path and site id.
				// Make sure the element has a route, too.
				$route = $element->getRoute();
				if ($route) {
					// Check to see if the element has an enabled version with the original site, in case it actually just has a different slug.
					$originalelement = Craft::$app->getElements()->getElementById($element->id, null, $originalsite->id);
					if ($originalelement && $originalelement->getStatus() == 'live') { // Found an element with a different slug that is available.
						Craft::$app->getResponse()->redirect($originalelement->getUrl(), 301); // Redirect to the proper element.
						Craft::$app->end(); // Redirect immediately, do not let Craft fall back to default 404 behavior (which wipes out the redirect).
					} else {
						// Do some reflection **magic** to substitute the element and route with the cached ones in Craft's UrlManager.
						// This is required because there is no other way to clear this cache short of replacing the UrlManager component
						// which results in a loss of all attached events and associated route parameters.
						// If Craft changes these properties, this will break. Ideally the UrlManager would provide means of resetting this internal state.
						$reflector = new \ReflectionClass(\craft\web\UrlManager::class);
						$elementproperty = $reflector->getProperty('_matchedElement');
						$elementproperty->setAccessible(true);
						$elementproperty->setValue(Craft::$app->getUrlManager(), $element);
						$routeproperty = $reflector->getProperty('_matchedElementRoute');
						$routeproperty->setAccessible(true);
						$routeproperty->setValue(Craft::$app->getUrlManager(), $route);
					}
				}
				return; // Found an entry, possibly redirected or rerouted (or one with no route). Work here is done, continue as normal.
			}
		}
	}
}
