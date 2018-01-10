<?php
/**
 * Default Site plugin for Craft 3.0
 * @copyright Copyright Charlie Development
 */

namespace charliedev\defaultsite;

use Craft;
use craft\base\Plugin;
use craft\events\ExceptionEvent;
use craft\helpers\ArrayHelper;
use craft\services\Fields;
use craft\web\ErrorHandler;
use craft\web\UrlManager;

use yii\base\Event;
use yii\web\HttpException;

/**
 * The main Craft plugin class.
 */
class DefaultSite extends Plugin
{
	/**
	 * @inheritdoc
	 * @see craft\base\Plugin
	 */
	public function init()
	{
		// Ideally it would be nice to do something where, between entry checking and dynamic routes,
		// the lack of an entry is caught and able to be optionally handled/replaced.
		// Because this isn't elegantly possible, another solution is to catch 404 errors and render
		// output if possible then, but this runs the risk of interfering/competing with other plugins
		// that may also perform actions on 404 pages, and also comes with the requirement of having
		// to force ending the script in order to avoid discarding the rendered output, which means
		// Yii/Craft never have the opportunity to finish what they're doing.
		/*
		Event::on(
			ErrorHandler::class,
			ErrorHandler::EVENT_BEFORE_HANDLE_EXCEPTION,
			function (ExceptionEvent $event) {
				// Don't do special 404 handling for console requests or previews.
				if (Craft::$app->getRequest()->getIsConsoleRequest() || !Craft::$app->getRequest()->getIsSiteRequest() || Craft::$app->getRequest()->getIsLivePreview()) {
					return;
				}

				$exception = $event->exception;
				
				if ($exception instanceof \Twig_Error_Runtime
					&& $exception->getPrevious() !== null) {
                    $exception = $exception->getPrevious();
                }
				
				if ($exception instanceof HttpException
					&& $exception->statusCode === 404) {
					$this->renderFallbackEntry();
                }
			},
			false
		);
		*/

		// This section, instead of going the event-based approach, will preemptively determine if
		// there is an element at the given URL, and attempt to find one if not, using as much of
		// Craft's built-in functionality as possible. The big difference between this and the
		// above code, is that it could potentially block requests from running custom routes and
		// direct templates, if an entry on a fallback site exists that conflicts with that path.
		// In addition, because it hooks into Craft's own system of rendering, it may be less likely
		// to interfere with other plugins or behaviors that would normally run for entry rendering
		// or on 404 handling.
		if (!Craft::$app->getRequest()->getIsConsoleRequest() && Craft::$app->getRequest()->getIsSiteRequest() && !Craft::$app->getRequest()->getIsLivePreview()) { // Only run for regular web frontend requests.
			$element = Craft::$app->getUrlManager()->getMatchedElement(); // Find the element that the current URL is going to.
			if ($element == null) { // No element found, this request will 404 normally.
				$originalsite = Craft::$app->getSites()->currentSite; // Get the current site, for reference.
				$checked = [$originalsite->id => true]; // Mark the current site as checked.
				$sites = $this->getSettings()->sites;
				$currentsite = Craft::$app->getSites()->currentSite->id;

				// Check the fallback for each site until a valid entry is found, or the fallback is a site that has already been checked .
				while (true) {
					if (!isset($sites[$currentsite])) { // No fallback (bad config, unconfigured site, etc.).
						return;
					}
					$currentsite = $sites[$currentsite];
					if (isset($checked[$currentsite])) { // Already checked this site, give up.
						return;
					}
					$checked[$currentsite] = true; // Mark this new site as checked.
					Craft::$app->getSites()->currentSite = Craft::$app->getSites()->getSiteById($currentsite); // Force Craft into new site.
					Craft::$app->set('urlManager', new UrlManager()); // Reset the URL Manager component, otherwise the request can't properly be re-processed under the new site.
					Craft::$app->getUrlManager()->parseRequest(Craft::$app->getRequest()); // Re-parse the request to find a potential new route.
					$element = Craft::$app->getUrlManager()->getMatchedElement(); // Find the element matched with the fallback site, if possible.
					Craft::$app->getSites()->currentSite = $originalsite; // Revert Craft to the original site, in case the element has been found.
					
					if ($element != false) { // If an element with the fallback site was found, check if it has an enabled version with the original site, in case of different slugs.
						$element = Craft::$app->getElements()->getElementById($element->id); // Try to retrieve the element with the same ID, using the original locale.
						if ($element && $element->getStatus() == 'live') { // The element exists in the original site and is enabled, redirect to it.
							Craft::$app->getRequest()->redirect($element->getUrl(), true, 301); // Element was found under original site, but with different slug.
						}
						return; // Element was found, but has no enabled version on the original site, fallback to it instead.
					}
				}
			}
		}

		parent::init();
	}

	/**
	 * @inheritdoc
	 * @see craft\base\Plugin
	 */
	protected function createSettingsModel()
    {
        return new \charliedev\defaultsite\models\Settings();
	}
	
	/**
	 * @inheritdoc
	 * @see craft\base\Plugin
	 */
	protected function settingsHtml()
    {
		$sites = Craft::$app->getSites()->getAllSites();
		$siteoptions = [];

		foreach ($sites as $site) {
			$siteoptions[$site->id] = Craft::t('site', $site->name);
		}
        return Craft::$app->getView()->renderTemplate('default-site/_settings', [
			'settings' => $this->getSettings(),
			'sites' => $siteoptions
        ]);
	}
	
	/**
	 * Attempts to find and render an entry from configured fallback sites if an entry hasn't been found for the visited site.
	 */
	private function renderFallbackEntry() {
		$originalsite = Craft::$app->getSites()->currentSite; // Store the original site being visited.
		$siteid = $originalsite->id; // Storage for the ID of the site currently being checked.
		$path = Craft::$app->getRequest()->getPathInfo(); // Retrieve the path being visited, used for checking for entries.
		$checked = [$originalsite->id => true]; // Mark the current site as checked.
		$sites = $this->getSettings()->sites; // Retrieve the set of sites and their fallbacks.

		// Check the fallback for each site until a valid entry is found, or the fallback is a site that has already been checked .
		while (true) {
			if (!isset($sites[$siteid])) { // No fallback (bad config, unconfigured site, etc.).
				return false; // Default back to normal 404 behavior.
			}
			$siteid = $sites[$siteid]; // Get the ID of the fallback site for the last checked site.
			if (isset($checked[$siteid])) { // Fallback site has already checked (avoid cyclical references), give up.
				return false; // Default back to normal 404 behavior.
			}
			
			$element = Craft::$app->getElements()->getElementByUri($path, $siteid, true); // Check for an entry with the same path in the fallback site.
			if ($element) { // An element was found with the given path and site id.
				
				// Make sure the element has a route, too.
				$route = $element->getRoute();
				if ($route) {

					// Check to see if the element has an enabled version with the original site, in case it actually just has a different slug.
					$originalelement = Craft::$app->getElements()->getElementById($element->id, null, $originalsite->id);
					if ($originalelement && $originalelement->getStatus() == 'live') { // Found an element with a different slug.
						Craft::$app->controller->redirect($originalelement->getUrl(), true, 301); // Redirect to the proper element.
						die(); // End here, so the output isn't cleared and we don't get a 404 response.
					} else {

						/** @see \yii\web\Request::resolve() */
						$route[1] += $_GET; // Additional parameters should be attached to match what Yii already does internally.

						$render = Craft::$app->runAction($route[0], $route[1]); // Render the template with the updated entry.
						$render->send(); // Send the rendered template to the user.
						die(); // End here, so the output isn't cleared and we don't get a 404 response.
					}
				}
				return false; // Found an entry, but it has no route? Probably not an entry meant to be visited, continue with 404.
			}
		}
	}
}
