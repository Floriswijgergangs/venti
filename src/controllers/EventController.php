<?php

/**
 * Venti plugin
 *
 *
 * @link      http://tippingmedia.com
 * @copyright Copyright (c) 2017 tippingmedia
 */

namespace tippingmedia\venti\controllers;

use tippingmedia\venti\Venti;
use tippingmedia\venti\controllers\BaseEventController;
use tippingmedia\venti\services\Events;
use tippingmedia\venti\services\Groups;
use tippingmedia\venti\services\Recurr;
use tippingmedia\venti\services\Rrule;
use tippingmedia\venti\services\Ics;
use tippingmedia\venti\models\Event;
use tippingmedia\venti\elements\VentiEvent;

use Craft;
use craft\web\Controller;
use craft\base\Field;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\elements\User;
use craft\helpers\DateTimeHelper;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craft\models\Site;
use craft\db\Query;
use DateTime;
use DateTimeZone;
use yii\base\Exception;
use yii\base\InvalidConfigException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\ServerErrorHttpException;

use craft\web\assets\editentry\EditEntryAsset;
use craft\web\assets\datepickeri18n\DatepickerI18nAsset;

use craft\i18n\Formatter;
use craft\i18n\Locale;

/**
 * Event Controller
 *
 *
 * @author    tippingmedia
 * @package   Venti
 * @since     2.0.0
 */

class EventController extends BaseEventController
{

	protected $allowAnonymous = [
		'actionViewEventByStartDate',
		'actionViewDetail',
		'actionViewEventByEid',
		'actionViewICS'
	];


	/**
	 * Event index
	 */
	public function actionIndex(array $variables = []): Response 
	{
		$variables['groups'] = Venti::getInstance()->groups->getAllGroups();
		return $this->renderTemplate('venti/_index', $variables);
	}

	/**
	 * Edit an event.
	 *
	 * @param array $variables
	 * @throws HttpException
	 */
	public function actionEditEvent(string $groupHandle, int $eventId = null, string $siteHandle = null, VentiEvent $event = null): Response
	{


		$variables = [
            'groupHandle' => $groupHandle,
            'eventId' => $eventId,
            'event' => $event
        ];


		if ($siteHandle !== null) {
            $variables['site'] = Craft::$app->getSites()->getSiteByHandle($siteHandle);

            if (!$variables['site']) {
                throw new NotFoundHttpException('Invalid site handle: '.$siteHandle);
            }
        }

		$this->_prepEditEntryVariables($variables);


		/** @var Site $site */
        $site = $variables['site'];
        /** @var VentiEvent $event */
        $event = $variables['event'];
        /** @var Group $group */
        $group = $variables['group'];

		// Make sure they have permission to edit this entry
		// $eventMOD = new Event();
		// if (array_key_exists('eventId',$variables)) {
		// 	$eventMOD->setAttribute('id',$variables['eventId']);
		// }
		// $eventMOD->setAttribute('locale',$variables['localeId']);
		// $eventMOD->setAttribute('groupId',$variables['group']['id']);


		$this->enforceEditEventPermissions($event);

		$currentUser = Craft::$app->getUser()->getIdentity();
		$variables['permissionSuffix'] = ':'.$event->groupId;

		$groups = new Groups();

		if (!empty($variables['groupHandle'])) {
			$variables['group'] = $groups->getGroupByHandle($variables['groupHandle']);
		} else if (!empty($variables['groupId'])) {
			$variables['group'] = $groups->getGroupById($variables['groupId']);
		}

		if (empty($variables['group'])) {
			throw new HttpException(404);
		}


		// Tabs
		$variables['tabs'] = [];

		foreach ($variables['group']->getFieldLayout()->getTabs() as $index => $tab) {
			// Do any of the fields on this tab have errors?
			$hasErrors = false;

			if ($variables['event']->hasErrors()) {
				foreach ($tab->getFields() as $field) {
					if ($variables['event']->getErrors($field->getField()->handle)) {
						$hasErrors = true;
						break;
					}
				}
			}

			$variables['tabs'][] = [
				'label' => $tab->name,
				'url'   => '#tab'.($index+1),
				'class' => ($hasErrors ? 'error' : null)
			];
		}

		if ($event->id === null) {
			$variables['title'] = Craft::t('venti','Create a new event');
		}
		else {
			$variables['title'] = $variables['event']->title = $event->title;
		}


		// Breadcrumbs
		$variables['crumbs'] = [
			[ 'label' => Craft::t('venti','Events'), 'url' => UrlHelper::url('venti') ],
			[ 'label' => $variables['group']->name, 'url' => UrlHelper::url('venti') ]
		];

		$variables['canDeleteEvent'] = $variables['event']->id && (
			($currentUser->can('deleteEvents'.$variables['permissionSuffix']))
		);



		// Enabled sites
		// ---------------------------------------------------------------------

		if (Craft::$app->getIsMultiSite()) {
			if ($event->id !== null) {
				$variables['enabledSiteIds'] = Craft::$app->getElements()->getEnabledSiteIdsForElement($event->id);
			} else {
				$variables['enabledSiteIds'] = [];

				foreach ($group->getGroupSiteSettings() as $siteSettings) {
					if ($siteSettings->enabledByDefault) {
                        $variables['enabledSiteIds'][] = $siteSettings->siteId;
                    }
				}
			}
		}

		// Set the base CP edit URL

        // Can't just use the event's getCpEditUrl() because that might include the site handle when we don't want it
		$variables['baseCpEditUrl'] = 'venti/'.$group->handle.'/{id}-{slug}';
		 // Set the "Continue Editing" URL
		$variables['continueEditingUrl'] = $variables['baseCpEditUrl'].
            (Craft::$app->getIsMultiSite() && Craft::$app->getSites()->currentSite->id != $site->id ? '/'.$site->handle : '');

		
		$variables['canDeleteEntry'] = (
            get_class($event) === VentiEvent::class &&
            $event->id !== null &&
            (
                ($currentUser->can('deleteEvents'.$variables['permissionSuffix']))
            )
        );


		$variables['fullPageForm'] = true;
		$variables['saveShortcutRedirect'] = $variables['continueEditingUrl'];


		// Render the template!
		//Craft::$app->getView()->includeCssResource('css/entry.css');
		$this->getView()->registerAssetBundle(EditEntryAsset::class);
		return $this->renderTemplate('venti/_edit', $variables);
	}

	/**
	 * Saves an event.
	 */
	public function actionSaveEvent() 
	{
		$this->requirePostRequest();

		$event = $this->_getEventModel();
		$request = Craft::$app->getRequest();

		$timezone = Craft::$app->getTimeZone();

		$this->enforceEditEventPermissions($event);
		$currentUser = Craft::$app->getUser()->getIdentity();

		$continueEditingUrl = $request->getBodyParam('continueEditingUrl');

		if ($request->getBodyParam('registration') && array_key_exists('type',$request->getBodyParam('registration'))) {
			$event->registration = $request->getBodyParam('registration');
		} else {
			$event->registration = null;
		}

		// $event->getContent()->title = $request->getBodyParam('title', $event->title);
		// $event->setContentFromPost('fields');

		$this->_populateEventModel($event);

		//permission enforcement
		if ($event->enabled) {
			if ($event->id) {
				$userSessionService->requirePermission('publishEvents:'.$event->groupId);
			} else if (!$currentUser->can('publishEvents:'.$event->groupId)) {
				$event->enabled = false;
			}
		}

		//\yii\helpers\VarDumper::dump($event, 5, true); exit;

		if (!Craft::$app->getElements()->saveElement($event)) {
			if ($request->getAcceptsJson()) {
				return $this->asJson([
					'errors' => $event->getErrors(),
				]);
			}

			Craft::$app->getSession()->setError(Craft::t('venti', 'Couldn’t save event.'));
			/* Send the event back to the template
				* newGroup is applied if event group was changed by select so tabs and fields pull from correct group.
				*/
			Craft::$app->getUrlManager()->setRouteParams([
				'event' => $event,
				'newGroup' => $event->getGroup()
			]);


			return null;
		} 

		if ($request->getAcceptsJson()) {

			$return = [];
			$return['success'] = true;
			$return['id'] = $event->id;
			$return['title'] = $event->title;

			if (!$request->getIsConsoleRequest() && $request->isCpRequest()) {
				$return['cpEditUrl'] = $event->getCpEditUrl();
			}

			$return['startDate'] = $event->startDate;
			$return['endDate'] = $event->startDate;
			$return['endRepeat'] = $event->endRepeat;
			$return['rRule'] = $event->rRule;
			$return['summary'] = $event->summary;
			$return['repeat'] = $event->repeat;
			$return['isrepeat'] = $event->isrepeat;
			$return['diff'] = $event->diff;
			$return['allDay'] = $event->allDay;
			$return['location'] = $event->location;
			$return['specificLocation'] = $event->location;
			$return['registration'] = $event->registration;
			$return['dateCreated'] = DateTimeHelper::toIso8601($event->dateCreated);
            $return['dateUpdated'] = DateTimeHelper::toIso8601($event->dateUpdated);

			return $this->asJson($return);
		} 

		Craft::$app->getSession()->setNotice(Craft::t('venti', 'Event saved.'));

		return $this->redirectToPostedUrl($event);
	}

	/**
	 * Deletes an event.
	 */
	public function actionDeleteEvent() 
	{
		$this->requirePostRequest();
        //$this->requireAcceptsJson();
		$groups = new Groups();

		$eventId = Craft::$app->getRequest()->getRequiredBodyParam('eventId');
		$siteId = Craft::$app->getRequest()->getBodyParam('siteId');
		$event = $groups->getEventById($eventId, $siteId);

		Craft::$app->getUser()->requirePermission('deleteEvents:'.$event->groupId);

		if (Craft::$app->getRequest()->getAcceptsJson()) {

            if(Craft::$app->getElements()->deleteElementById($eventId)) {
				Craft::$app->getSession()->setNotice(Craft::t('Venti','Event deleted'));
                return $this->asJson([
                    'success' => true
				]);
            } else {
				Craft::$app->getSession()->setError(Craft::t('Venti','Couldn’t delete event'));
                $this->asJson([
                    'errors' => $event->getErrors(),
				]);
            }
        } else {
			if (Craft::$app->getElements()->deleteElementById($eventId)) {
				Craft::$app->getSession()->setNotice(Craft::t('Venti','Event deleted'));
				$this->redirectToPostedUrl($event);
			} else {
				Craft::$app->getSession()->setError(Craft::t('Venti','Couldn’t delete event'));
			}
		}
	}


	/**
	 * Fetches or creates an Venti_EventModel.
	 *
	 * @throws Exception
	 * @return Venti_EventModel
	 */
	private function _getEventModel() 
	{
		$eventId = Craft::$app->getRequest()->getBodyParam('eventId');
		$siteId = Craft::$app->getRequest()->getBodyParam('siteId');

		$groups = new Groups();

		if ($eventId) {
			$event = $groups->getEventById($eventId, $siteId);

			if (!$event) {
				throw new Exception(Craft::t('venti','No event exists with the ID “{id}”.', array('id' => $eventId)));
			}
		} else {
			$event = new VentiEvent();
			$event->groupId = Craft::$app->getRequest()->getRequiredBodyParam('groupId');

			if ($siteId) {
				$event->siteId = $siteId;
			}
		}

		return $event;
	}


	/*
     *
     *
     */
    public function actionRecurTextTransform() 
	{
        if (Craft::$app->getRequest()->getAcceptsJson()) {
        	$rule = Craft::$app->getRequest()->getBodyParam('rule');
        	$lang = Craft::$app->getRequest()->getBodyParam('lang') ? Craft::$app->getRequest()->getBodyParam('lang') : null;
        	$text = recurTextTransform($rule, $lang);
        	return $this->asJson($text);
		}
		return null;
    }


	/**
	 * Get Recur Rule String
	 * @return string
	 */

	public function actionGetRuleString() 
	{
		$this->requireAcceptsJson();
		$rules = new Rrule();
        if (Craft::$app->getRequest()->getAcceptsJson()) {
			$post = Craft::$app->getRequest()->getBodyParams();
			$repeat = reset($post)['repeat'];
			//$locale = array_key_exists('locale', $repeat) ? $repeat['locale'] : Craft::$app->getLocale();
			$localeData = Craft::$app->locale;
			$lang = $localeData->id;
			$ruleString = $rules->getRRule($repeat);
			$ruleHumanString = $rules->recurTextTransform($ruleString, $lang);
			$dateDict = $rules->getIncludedExcludedDates($ruleString);
			$output = [
				"rrule" => $ruleString,
				"readable" => $ruleHumanString,
				"excluded" => [],
				"included" => [],
			];
			if($dateDict != false) {
				if (array_key_exists('excludedDates',$dateDict)) {
					$output['excluded'] = $dateDict['excludedDates'];
				}
				if (array_key_exists('includedDates',$dateDict)) {
					$output['included'] = $dateDict['includedDates'];
				}
			}

        	return $this->asJson($output);
		}
		return null;
    }



	/**
	 * Switches between two groups.
	 *
	 * @return null
	 */
	public function actionSwitchGroup() 
	{
		$this->requirePostRequest();

		if (Craft::$app->getRequest()->getAcceptsJson()) {

			$event = $this->_getEventModel();
			$this->enforceEditEventPermissions($event);
			$this->_populateEventModel($event);

			$variables['groupId'] = $event->groupId;
			$variables['event'] = $event;
			$variables['element'] = Craft::$app->getElements()->getElementById($event->id);

			$this->_prepEditEntryVariables($variables);

			$paneHtml = Craft::$app->getView()->render('venti/events/_tabs', $variables) .
				Craft::$app->getView()->render('venti/events/_fields', $variables);

			return $this->asJson([
				'variables' => $variables,
				'paneHtml' => $paneHtml,
				'headHtml' => Craft::$app->getView()->getHeadHtml(),
				'footHtml' => Craft::$app->getView()->getFootHtml(),
			]);
		}

		return null;
	}


	/*
     * Render repeat date modal from ajax call.
     */
    public function actionModal(): Response
	{
		//$this->requirePostRequest();
		//$this->requireAcceptsJson();
        
		$defaultValues = [
			"frequency" => 0,
			'by' => ['0'],
			'endsOn' => ['0'],
			'on'  => [],
			'starts' => '',
			'enddate' => '',
			'occur' => '',
			'every' => '',
			'exclude' => []
		];

		$rule = Craft::$app->getRequest()->getBodyParam("rrule");
		$siteId = Craft::$app->getRequest()->getBodyParam("siteId");
		$values = $rule != "" ?  Venti::getInstance()->rrule->modalValuesArray($rule) : $defaultValues;

		$view = $this->getView();
		$view->registerAssetBundle(DatepickerI18nAsset::class);

		$html = $view->renderTemplate('venti/_modal',['values' => $values, 'siteId' => $siteId]);
		$headHtml = $view->getHeadHtml();

		return $this->asJson(['html' => $html, 'headHtml' => $headHtml]);
    }


/**
	 * Preps entry edit variables.
	 *
	 * @param array &$variables
	 *
	 * @throws HttpException|Exception
	 * @return null
	 */
	private function _prepEditEntryVariables(&$variables) 
	{
		$groups = new Groups();
		// Get the group
		// ---------------------------------------------------------------------

		if (!empty($variables['groupHandle'])) {
			$variables['group'] = $groups->getGroupByHandle($variables['groupHandle']);
		} else if (!empty($variables['groupId'])) {
			$variables['group'] = $groups->getGroupById($variables['groupId']);
		}

		if (empty($variables['group'])) {
			throw new NotFoundHttpException('Group not found');
		}

		// Get the locale date/time formats
		// ---------------------------------------------------------------------
		// $localeData = craft()->i18n->getLocaleData(craft()->language);
        // $dateFormatter = $localeData->getDateFormatter();
		//$dateFormat = Craft::$app->formatter->dateTimeFormats['medium']['date'];
		$dateFormat = Craft::$app->locale->getDateFormat('short',Locale::FORMAT_PHP);
        $timeFormat = Craft::$app->locale->getTimeFormat('short',Locale::FORMAT_PHP);

		$variables['dateFormat'] = $dateFormat;
		$variables['timeFormat'] = $timeFormat;



		if (Craft::$app->getIsMultiSite()) {
			#-- Only use the sites that the user has access to
			$groupSiteIds = array_keys($variables['group']->getGroupSiteSettings());
			$editableSiteIds = Craft::$app->getSites()->getEditableSiteIds();
			$variables['siteIds'] = array_merge(array_intersect($groupSiteIds, $editableSiteIds));
		} else {
			$variables['siteIds'] = [Craft::$app->getSites()->getPrimarySite()->id];
		}

		if (!$variables['siteIds']) {
			throw new HttpException(403, Craft::t('Venti','Your account doesn’t have permission to edit any of this groups’ sites.'));
		}

		if (empty($variables['site'])) {
			$variables['site'] = Craft::$app->getSites()->currentSite;

			if (!in_array($variables['site']->id, $variables['siteIds'])) {
				$variables['site'] = Craft::$app->getSites()->getSiteById($variables['siteIds'][0]);
			}

			$site = $variables['site'];
		} else {
			#-- Make sure they were requesting a valid site
			$site = $variables['site'];
            if (!in_array($site->id, $variables['siteIds'], false)) {
                throw new ForbiddenHttpException('User not permitted to edit content in this site');
            }
		}


        if (empty($variables['event'])) {
            if (!empty($variables['eventId'])) {
                
				$variables['event'] = Venti::getInstance()->events->getEventById($variables['eventId'], $site->id);

                if (!$variables['event']) {
                    throw new NotFoundHttpException('Entry not found');
                }
            } else {
                $variables['event'] = new VentiEvent();
                $variables['event']->groupId = $variables['group']->id;
                //$variables['event']->enabled = true;
                //$variables['event']->siteId = $site->id;

                if (Craft::$app->getIsMultiSite()) {
                    // Set the default site status based on the section's settings
                    foreach ($variables['group']->getGroupSiteSettings() as $siteSettings) {
                        if ($siteSettings->siteId == $variables['event']->siteId) {
                            $variables['event']->enabledForSite = $siteSettings->enabledByDefault;
                            break;
                        }
                    }
                } else {
                    // Set the default entry status based on the section's settings
                    /** @noinspection LoopWhichDoesNotLoopInspection */
                    foreach ($variables['group']->getGroupSiteSettings() as $siteSettings) {
                        if (!$siteSettings->enabledByDefault) {
                            $variables['event']->enabled = false;
                        }
                        break;
                    }
                }
            }
        }


		// Redirect Urls
		// ---------------------------------------------------------------------

		// if (array_key_exists('event', $variables)) {
		// 	// Can't just use the entry's getCpEditUrl() because that might include the site ID when we don't want it
		// 	$variables['baseCpEditUrl'] = 'venti/'.$variables['group']['handle'].'/'.$variables['event']['id'].'-'.$variables['event']['slug'];
		// 	// on command save go back to event
		// 	$variables['saveShortcutRedirect'] = $variables['baseCpEditUrl'] .
		// 		(Craft::$app->getIsMultiSite() != $variables['siteId'] ? '/'.$variables['siteId'] : '');
		// 	// Set the "Continue Editing" URL
		// 	$variables['continueEditingUrl'] = $variables['baseCpEditUrl'] .
		// 		(Craft::$app->getIsMultiSite() != $variables['siteId'] ? '/'.$variables['siteId'] : '');
		// }

	}

	public function actionViewEventByEid(array $variables = []) 
	{

		$event = VentiEvent::find()
    		->eid($variables['eid'])
    		->siteEndabled(null)
    		->one();

		$group = getGroupById($event['groupId']);
		$template = $group['template'];

		$this->renderTemplate($template,['event' => $event],false);
	}

	#-- slug & startdate (YYYY-mm-dd)
	public function actionViewEventByStartDate(array $variables = []) 
	{
		$startDate = new DateTime($variables['year'] . "-" . $variables['month'] . "-" . $variables['day']);

		$event = VentiEvent::find()
			->slug($variables['slug'])
			->startDate([
				'and',
				'>='.$startDate->format('Y-m-d'), 
				'<='.$startDate->modify('+1 day')->format('Y-m-d')
			])
    		->siteEndabled(null)
    		->one();

		$group = getGroupById($event['groupId']);
		$template = $group['template'];

		$this->renderTemplate($template,['event' => $event],false);

	}

	public function actionViewDetail() 
	{
		$segments = Craft::$app->getRequest()->getSegments();
		$query = VentiEvent::find();
		$params = [];

		if(DateTime::createFromFormat('Y-m-d', end($segments)) !== FALSE) {
			$startDate = new DateTime(end($segments));
			$params['startDate'] = [
				'and',
				'>='.$startDate->format('Y-m-d'), 
				'<='.$startDate->modify('+1 day')->format('Y-m-d')
			];

		} elseif(is_numeric(end($segments))) {
			$params['eid'] = end($segments);
		}

		#-- assuming second item in segments is slug of event.
		$params['slug'] = $segments[1];
	    $params['siteEnabled'] = null;
		$event = Craft::configure($query,$params);

		$group = getGroupById($event['groupId']);
		$template = $group['template'];

		$this->renderTemplate($template,['event' => $event],false);
	}


	public function actionUpdateEventDates() 
	{
		$this->requirePostRequest();

		$timezone = new DateTimeZone(Craft::$app->getTimeZone());
		#-- ID of event being updated
		$id = Craft::$app->getRequest()->getBodyParam('id');
		#-- Grab original event by id
		$element = Craft::$app->getElements()->getElementById($id);

		#-- Create date times of start & end dates
		$start = new DateTime(Craft::$app->getRequest()->getBodyParam('start'), $timezone);
		$end = new DateTime(Craft::$app->getRequest()->getBodyParam('end'), $timezone);
		$siteId = Craft::$app->getRequest()->getBodyParam('siteId');


		$model = new Event();
		$model->setAttributes($element->getAttributes());
		$model->startDate = $start;
		$model->endDate = $end;

		if($element->repeat == 1) {
			$rrule = updateDTStartEnd($element->rRule, $start, $end);
			$model->rRule = $rrule;
			$model->repeat = 1;
		}

		if(!saveEvent($model,$siteId)) {
			Craft::$app->getSession()->setError(Craft::t('Venti','Event updates could not be completed.'));
			if (Craft::$app->getRequest()->getAcceptsJson()) {
				return $this->asJson([
					'errors' => $model->getErrors(),
				]);
			}
		} else {
			if (Craft::$app->getRequest()->getAcceptsJson()) {
				$return['success'] = true;
				return $this->asJson($return);
			}
		}
    }

	public function actionViewICS() {	
		$segments = Craft::$app->getRequest()->getSegments();
		return renderICSFile($segments[2]);
	}


	public function actionRemoveOccurence() 
	{
		$this->requirePostRequest();

		$timezone = new DateTimeZone(Craft::$app->getTimeZone());
		#-- ID of event being updated
		$id = Craft::$app->getRequest()->getBodyParam('id');
		#-- Grab original event by id
		$element = Craft::$app->getElements()->getElementById($id);

		$exDate = new DateTime(Craft::$app->getRequest()->getBodyParam('exDate'), $timezone);
		$siteId = Craft::$app->getRequest()->getBodyParam('siteId');

		$model = new Event();
		$model->setAttributes($element->getAttributes());

		if($element->repeat == 1) {
			$rrule = addExcludedDate($element->rRule, $exDate, $siteId );
			$model->rRule = $rrule;
			$model->repeat = 1;
		}


		if(!saveEvent($model,$siteId)) {
			Craft::$app->getSession()->setError(Craft::t('Venti','Event updates could not be completed.'));
			if (Craft::$app->getRequest()->getAcceptsJson()) {
				return $this->asJson([
					'errors' => $model->getErrors(),
				]);
			}
		} else {
			if (Craft::$app->getRequest()->getAcceptsJson()) {
				$return['success'] = true;
				return $this->asJson($return);
			}
		}
	}

	/**
	 * Populates an EventModel with post data.
	 *
	 * @param EventModel $event
	 *
	 * @return null
	 */
	public function _populateEventModel(VentiEvent $event) 
	{
		$event->slug          = Craft::$app->getRequest()->getBodyParam('slug', $event->slug);
		$event->groupId       = Craft::$app->getRequest()->getBodyParam('groupId', $event->groupId);
		$event->startDate     = (($startDate = Craft::$app->getRequest()->getBodyParam('startDate')) ? DateTimeHelper::toDateTime($startDate) : null);
		$event->endDate    	  = (($endDate   = Craft::$app->getRequest()->getBodyParam('endDate'))   ? DateTimeHelper::toDateTime($endDate) : null);
		$event->rRule     	  = Craft::$app->getRequest()->getBodyParam('rRule', $event->rRule);
		$event->repeat 		  = (bool) Craft::$app->getRequest()->getBodyParam('repeat', $event->repeat);
		$event->summary 	  = Craft::$app->getRequest()->getBodyParam('summary', $event->summary);
		$event->allDay 		  = (bool) Craft::$app->getRequest()->getBodyParam('allDay', $event->allDay);
		$event->location 	  = Craft::$app->getRequest()->getBodyParam('location', $event->location);
		$event->registration  = Craft::$app->getRequest()->getBodyParam('registration', $event->registration);
		$event->enabled = (bool)Craft::$app->getRequest()->getBodyParam('enabled', $event->enabled);
        $event->enabledForSite = (bool)Craft::$app->getRequest()->getBodyParam('enabledForSite', $event->enabledForSite);
        $event->title = Craft::$app->getRequest()->getBodyParam('title', $event->title);

		$event->isrepeat 	  = null;
		$event->diff     	  = null;
		$event->endRepeat     = null;

		if($event->repeat) {
			$recurr = new Recurr();
			$dates  = $recurr->getRecurDates($event->startDate,$event->rRule);
        	$dates  = $dates->toArray();
			$lastDate = end($dates);
			
			$event->diff = $event->startDate->diff($event->endDate);
			$event->endRepeat = $lastDate->getEnd();
		}

		$event->fieldLayoutId = $event->getGroup()->fieldLayoutId;
		$fieldsLocation = Craft::$app->getRequest()->getParam('fieldsLocation', 'fields');
		$event->setFieldValuesFromRequest($fieldsLocation);
	}

}