<?php
/**
 * Default Site plugin for Craft 3.0
 * @copyright Copyright Charlie Development
 */

namespace charliedev\defaultsite\models;

use Craft;
use craft\base\Model;

/**
 * The plugin settings model for the Default Site plugin.
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
	 * Makes sure that the sites and their defaults are valid.
	 */
	public function validateSites(string $attribute) {
		if (!is_array($this->sites)) {
			$this->addError($attribute, Craft::t('default-site', 'Sites must be provided as an array'));
			return;
		}
		$sites = Craft::$app->getSites()->getAllSiteIds();
		foreach ($this->sites as $site => $default) {
			if (!in_array($site, $sites)) {
				$this->addError($attribute, Craft::t('default-site', '"' . $site . '" is not a valid site ID'));
			}
			if (!in_array($site, $sites)) {
				$this->addError($attribute, Craft::t('default-site', '"' . $default . '" is not a valid site ID'));
			}
		}
	}
}
