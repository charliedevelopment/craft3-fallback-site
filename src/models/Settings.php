<?php
/**
 * Fallback Site plugin for Craft 3.0
 * @copyright Copyright Charlie Development
 */

namespace charliedev\fallbacksite\models;

use Craft;
use craft\base\Model;

/**
 * The plugin settings model for the Fallback Site plugin.
 */
class Settings extends Model
{

	public $sites = [];

	/**
	 * @inheritdoc
	 * @see yii\base\BaseObject
	 */
	public function init() {

		$sites = Craft::$app->getSites()->getAllSiteIds();

		// Each site falls back to itself alone.
		foreach ($sites as $site) {
			$this->sites[$site] = $site;
		}

		parent::init();
	}

	/**
	 * @inheritdoc
	 * @see craft\base\Model
	 */
	public function rules() {
		return [
			['sites', 'validateSites']
		];
	}

	/**
	 * Makes sure that the sites and their configured fallbacks are valid.
	 */
	public function validateSites(string $attribute) {
		if (!is_array($this->sites)) {
			$this->addError($attribute, Craft::t('fallback-site', 'Sites must be provided as an array'));
			return;
		}
		$sites = Craft::$app->getSites()->getAllSiteIds();
		foreach ($this->sites as $site => $fallback) {
			if (!in_array($site, $sites)) {
				$this->addError($attribute, Craft::t('fallback-site', '"' . $site . '" is not a valid site ID'));
			}
			if (!in_array($site, $sites)) {
				$this->addError($attribute, Craft::t('fallback-site', '"' . $fallback . '" is not a valid site ID'));
			}
		}
	}
}
