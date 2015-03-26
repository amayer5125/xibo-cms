<?php
/*
 * Xibo - Digital Signage - http://www.xibo.org.uk
 * Copyright (C) 2006-2015 Daniel Garner
 *
 * This file is part of Xibo.
 *
 * Xibo is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version. 
 *
 * Xibo is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with Xibo.  If not, see <http://www.gnu.org/licenses/>.
 *
 */ 
include_once('modules/3rdparty/forecast.php');
use Forecast\Forecast;
use Widget\Module;
use Xibo\Helper\ApplicationState;
use Xibo\Helper\Cache;
use Xibo\Helper\Config;
use Xibo\Helper\Date;
use Xibo\Helper\Form;
use Xibo\Helper\Log;
use Xibo\Helper\Theme;
use Xibo\Helper\Translate;

class ForecastIo extends Module
{
    private $resourceFolder;
    private $codeSchemaVersion = 1;

    /**
     * Install or Update this module
     */
    public function InstallOrUpdate()
    {
        // This function should update the `module` table with information about your module.
        // The current version of the module in the database can be obtained in $this->schemaVersion
        // The current version of this code can be obtained in $this->codeSchemaVersion
        
        // $settings will be made available to all instances of your module in $this->settings. These are global settings to your module, 
        // not instance specific (i.e. not settings specific to the layout you are adding the module to).
        // $settings will be collected from the Administration -> Modules CMS page.
        // 
        // Layout specific settings should be managed with $this->SetOption in your add / edit forms.
        
        if ($this->module->schemaVersion <= 1) {
            // Install
            $this->InstallModule('Forecast IO', 'Weather forecasting from Forecast IO', 'forms/library.gif', 1, 1, array());
        }
        else {
            // Update
            // Call "$this->UpdateModule($name, $description, $imageUri, $previewEnabled, $assignable, $settings)" with the updated items
        }

        // Check we are all installed
        $this->InstallFiles();

        // After calling either Install or Update your code schema version will match the database schema version and this method will not be called
        // again. This means that if you want to change those fields in an update to your module, you will need to increment your codeSchemaVersion.
    }

    /**
     * Form for updating the module settings
     */
    public function ModuleSettingsForm()
    {
        // Output any form fields (formatted via a Theme file)
        // These are appended to the bottom of the "Edit" form in Module Administration
        
        // API Key
        $formFields[] = Form::AddText('apiKey', __('API Key'), $this->GetSetting('apiKey'),
            __('Enter your API Key from Forecast IO.'), 'a', 'required');
        
        // Cache Period
        $formFields[] = Form::AddText('cachePeriod', __('Cache Period'), $this->GetSetting('cachePeriod', 300),
            __('Enter the number of seconds you would like to cache long/lat requests for. Forecast IO offers 1000 requests a day.'), 'c', 'required');
        
        return $formFields;
    }

    public function InstallFiles()
    {
        $media = new Media();
        $media->addModuleFile('modules/preview/vendor/jquery-1.11.1.min.js');
        $media->addModuleFile('modules/preview/xibo-layout-scaler.js');
        $media->addModuleFileFromFolder($this->resourceFolder);
    }

    /**
     * Process any module settings
     */
    public function ModuleSettings()
    {
        // Process any module settings you asked for.
        $apiKey = \Kit::GetParam('apiKey', _POST, _STRING, '');

        if ($apiKey == '')
            throw new InvalidArgumentException(__('Missing API Key'));

        $this->module->settings['apiKey'] = $apiKey;
        $this->module->settings['cachePeriod'] = \Kit::GetParam('cachePeriod', _POST, _INT, 300);

        // Return an array of the processed settings.
        return $this->module->settings;
    }

    /** 
     * Loads templates for this module
     */
    public function loadTemplates()
    {
        // Scan the folder for template files
        foreach (glob('modules/theme/forecastio/*.template.json') as $template) {
            // Read the contents, json_decode and add to the array
            $this->module->settings['templates'][] = json_decode(file_get_contents($template), true);
        }

        Log::debug(count($this->module->settings['templates']));
    }
    
    /**
     * Add Form
     */
    public function AddForm()
    {
        $response = $this->getState();

        // You also have access to $settings, which is the array of settings you configured for your module.
        // Augment settings with templates
        $this->loadTemplates();

        // Configure form
        $this->configureForm('AddMedia');

        // Two tabs
        $tabs = array();
        $tabs[] = Form::AddTab('general', __('General'));
        $tabs[] = Form::AddTab('advanced', __('Appearance'));
        $tabs[] = Form::AddTab('forecast', __('Forecast'));

        Theme::Set('form_tabs', $tabs);

	$formFields['general'][] = Form::AddText('name', __('Name'), NULL,
            __('An optional name for this media'), 'n');

        $formFields['general'][] = Form::AddNumber('duration', __('Duration'), NULL,
            __('The duration in seconds this item should be displayed.'), 'd', 'required');

        $formFields['general'][] = Form::AddCheckbox('useDisplayLocation', __('Use the Display Location'), $this->GetOption('useDisplayLocation'),
            __('Use the location configured on the display'), 'd');

        // Any values for the form fields should be added to the theme here.
        $formFields['general'][] = Form::AddNumber('latitude', __('Latitude'), $this->GetOption('latitude'),
            __('The Latitude for this weather module'), 'l', '', 'locationControls');

        $formFields['general'][] = Form::AddNumber('longitude', __('Longitude'), $this->GetOption('longitude'),
            __('The Longitude for this weather module'), 'g', '', 'locationControls');

        $formFields['advanced'][] = Form::AddCombo('templateId', __('Weather Template'), $this->GetOption('templateId'),
            $this->module->settings['templates'],
            'id', 
            'value', 
            __('Select the template you would like to apply. This can be overridden using the check box below.'), 't', 'template-selector-control');

        $formFields['advanced'][] = Form::AddCombo('icons', __('Icons'), $this->GetOption('icons'),
            $this->iconsAvailable(), 
            'id', 
            'value', 
            __('Select the icon set you would like to use.'), 't', 'icon-controls');

        $formFields['advanced'][] = Form::AddNumber('size', __('Size'), $this->GetOption('size', 1),
            __('Set the size. Start at 1 and work up until the widget fits your region appropriately.'), 's', 'number', 'template-selector-control');

        $formFields['advanced'][] = Form::AddCombo('units', __('Units'), $this->GetOption('units'),
            $this->unitsAvailable(), 
            'id', 
            'value', 
            __('Select the units you would like to use.'), 'u');

        $formFields['advanced'][] = Form::AddCombo('lang', __('Language'), Translate::GetLocale(2),
            $this->supportedLanguages(),
            'id',
            'value',
            __('Select the language you would like to use.'), 'l');

	$formFields['general'][] = Form::AddText('color', __('Colour'), '#000',
            __('Please select a colour for the foreground text.'), 'c', 'required');

        $formFields['advanced'][] = Form::AddCheckbox('overrideTemplate', __('Override the template?'), 0,
            __('Tick if you would like to override the template.'), 'o');

        $formFields['advanced'][] = Form::AddMultiText('currentTemplate', __('Template for Current Forecast'), NULL,
            __('Enter the template for the current forecast. For a list of substitutions click "Request Forecast" below.'), 't', 10, 'required', 'template-override-controls');

        $formFields['advanced'][] = Form::AddMultiText('dailyTemplate', __('Template for Daily Forecast'), NULL,
            __('Enter the template for the daily forecast. Replaces [dailyForecast] in main template.'), 't', 10, NULL, 'template-override-controls');

        $formFields['advanced'][] = Form::AddMultiText('styleSheet', __('CSS Style Sheet'), NULL, __('Enter a CSS style sheet to style the weather widget'), 'c', 10, 'required', 'template-override-controls');
        
        $formFields['forecast'][] = Form::AddMessage(__('Please press Request Forecast'));

        // Configure the field dependencies
        $this->SetFieldDepencencies($response);

        // Append the Templates to the response
        $response->extra = $this->module->settings['templates'];

        // Modules should be rendered using the theme engine.
        Theme::Set('form_fields_general', $formFields['general']);
        Theme::Set('form_fields_advanced', $formFields['advanced']);
        Theme::Set('form_fields_forecast', $formFields['forecast']);
        $response->html = Theme::RenderReturn('form_render');

        // The response object outputs the required JSON object to the browser
        // which is then processed by the CMS JavaScript library (xibo-cms.js).
        $response->AddButton(__('Request Forecast'), 'requestTab("forecast", "index.php?p=module&q=exec&mod=' . $this->getModuleType() . '&method=requestTab&widgetId=' . $this->getWidgetId() . '")');
        $this->configureFormButtons($response);

        $response->dialogTitle = __('Forecast IO');
        $response->callBack = 'forecastIoFormSetup';

        // The response must be returned.
        return $response;
    }

    /**
     * Add Media to the Database
     */
    public function AddMedia()
    {
        $response = $this->getState();


        // You can store any additional options for your module using the SetOption method
        $this->SetOption('name', \Kit::GetParam('name', _POST, _STRING));
        $this->setDuration(Kit::GetParam('duration', _POST, _INT, $this->getDuration(), false));
        $this->SetOption('useDisplayLocation', \Kit::GetParam('useDisplayLocation', _POST, _CHECKBOX));
        $this->SetOption('color', \Kit::GetParam('color', _POST, _STRING));
        $this->SetOption('longitude', \Kit::GetParam('longitude', _POST, _DOUBLE));
        $this->SetOption('latitude', \Kit::GetParam('latitude', _POST, _DOUBLE));
        $this->SetOption('templateId', \Kit::GetParam('templateId', _POST, _STRING));
        $this->SetOption('icons', \Kit::GetParam('icons', _POST, _STRING));
        $this->SetOption('overrideTemplate', \Kit::GetParam('overrideTemplate', _POST, _CHECKBOX));
        $this->SetOption('size', \Kit::GetParam('size', _POST, _INT));
        $this->SetOption('units', \Kit::GetParam('units', _POST, _WORD));
        $this->SetOption('lang', \Kit::GetParam('lang', _POST, _WORD));

        $this->setRawNode('styleSheet', \Kit::GetParam('styleSheet', _POST, _HTMLSTRING));
        $this->setRawNode('currentTemplate', \Kit::GetParam('currentTemplate', _POST, _HTMLSTRING));
        $this->setRawNode('dailyTemplate', \Kit::GetParam('dailyTemplate', _POST, _HTMLSTRING));

        // Save the widget
        $this->saveWidget();

        // Load form
        $response->loadForm = true;
        $response->loadFormUri = $this->getTimelineLink();

        return $response;
    }

    /**
     * Return the Edit Form
     */
    public function EditForm()
    {
        $response = $this->getState();

        if (!$this->auth->edit)
            throw new Exception(__('You do not have permission to edit this widget.'));

        // Configure the form
        $this->configureForm('EditMedia');

        // Augment settings with templates
        $this->loadTemplates();

        // Two tabs
        $tabs = array();
        $tabs[] = Form::AddTab('general', __('General'));
        $tabs[] = Form::AddTab('advanced', __('Appearance'));
        $tabs[] = Form::AddTab('forecast', __('Forecast'));

        Theme::Set('form_tabs', $tabs);

	$formFields['general'][] = Form::AddText('name', __('Name'), $this->GetOption('name'),
            __('An optional name for this media'), 'n');

        $formFields['general'][] = Form::AddNumber('duration', __('Duration'), $this->getDuration(),
            __('The duration in seconds this item should be displayed.'), 'd', 'required');

        $formFields['general'][] = Form::AddCheckbox('useDisplayLocation', __('Use the Display Location'), $this->GetOption('useDisplayLocation'),
            __('Use the location configured on the display'), 'd');

        // Any values for the form fields should be added to the theme here.
        $formFields['general'][] = Form::AddNumber('latitude', __('Latitude'), $this->GetOption('latitude'),
            __('The Latitude for this weather module'), 'l', '', 'locationControls');

        $formFields['general'][] = Form::AddNumber('longitude', __('Longitude'), $this->GetOption('longitude'),
            __('The Longitude for this weather module'), 'g', '', 'locationControls');

        $formFields['advanced'][] = Form::AddCombo('templateId', __('Weather Template'), $this->GetOption('templateId'),
            $this->module->settings['templates'], 
            'id', 
            'value', 
            __('Select the template you would like to apply. This can be overridden using the check box below.'), 't', 'template-selector-control');

        $formFields['advanced'][] = Form::AddCombo('icons', __('Icons'), $this->GetOption('icons'),
            $this->iconsAvailable(), 
            'id', 
            'value', 
            __('Select the icon set you would like to use.'), 't', 'icon-controls');

        $formFields['advanced'][] = Form::AddNumber('size', __('Size'), $this->GetOption('size', 1),
            __('Set the size. Start at 1 and work up until the widget fits your region appropriately.'), 's', 'number', 'template-selector-control');

        $formFields['advanced'][] = Form::AddCombo('units', __('Units'), $this->GetOption('units'),
            $this->unitsAvailable(), 
            'id', 
            'value', 
            __('Select the units you would like to use.'), 'u');

        $formFields['advanced'][] = Form::AddCombo('lang', __('Language'), $this->GetOption('lang', Translate::GetLocale(2)),
            $this->supportedLanguages(),
            'id',
            'value',
            __('Select the language you would like to use.'), 'l');

        $formFields['general'][] = Form::AddText('color', __('Colour'), $this->GetOption('color', '000'),
            __('Please select a colour for the foreground text.'), 'c', 'required');

        $formFields['advanced'][] = Form::AddCheckbox('overrideTemplate', __('Override the template?'), $this->GetOption('overrideTemplate'),
            __('Tick if you would like to override the template.'), 'o');

        $formFields['advanced'][] = Form::AddMultiText('currentTemplate', __('Template for Current Forecast'), $this->getRawNode('currentTemplate', null),
            __('Enter the template for the current forecast. For a list of substitutions click "Request Forecast" below.'), 't', 10, 'required', 'template-override-controls');

        $formFields['advanced'][] = Form::AddMultiText('dailyTemplate', __('Template for Daily Forecast'), $this->getRawNode('dailyTemplate', null),
            __('Enter the template for the current forecast. Replaces [dailyForecast] in main template.'), 't', 10, NULL, 'template-override-controls');

        $formFields['advanced'][] = Form::AddMultiText('styleSheet', __('CSS Style Sheet'), $this->getRawNode('styleSheet', null),
            __('Enter a CSS style sheet to style the weather widget'), 'c', 10, 'required', 'template-override-controls');
        
        $formFields['forecast'][] = Form::AddMessage(__('Please press Request Forecast to show the current forecast and all available substitutions.'));

        // Encode up the template
        if ($this->getUser()->userTypeId == 1)
            $formFields['forecast'][] = Form::AddMessage('<pre>' . htmlentities(json_encode(array('id' => 'ID', 'value' => 'TITLE', 'main' => $this->getRawNode('currentTemplate', null), 'daily' => $this->getRawNode('dailyTemplate', null), 'css' => $this->getRawNode('styleSheet', null)))) . '</pre>');

        // Configure the field dependencies
        $this->SetFieldDepencencies($response);

        // Append the Templates to the response
        $response->extra = $this->module->settings['templates'];

        // Modules should be rendered using the theme engine.
        Theme::Set('form_fields_general', $formFields['general']);
        Theme::Set('form_fields_advanced', $formFields['advanced']);
        Theme::Set('form_fields_forecast', $formFields['forecast']);
        $response->html = Theme::RenderReturn('form_render');

        $this->configureFormButtons($response);
        $response->dialogTitle = __('Forecast IO');
        $response->callBack = 'forecastIoFormSetup';
        // The response object outputs the required JSON object to the browser
        // which is then processed by the CMS JavaScript library (xibo-cms.js).
        $response->AddButton(__('Request Forecast'), 'requestTab("forecast", "index.php?p=module&q=exec&mod=' . $this->getModuleType() . '&method=requestTab&widgetId=' . $this->getWidgetId() . '")');
        $this->response->AddButton(__('Apply'), 'XiboDialogApply("#ModuleForm")');

        // The response must be returned.
        return $response;
    }

    /**
     * Edit Media in the Database
     */
    public function EditMedia()
    {
        $response = $this->getState();

        if (!$this->auth->edit)
            throw new Exception(__('You do not have permission to edit this widget.'));

        //Other Properties
	$name = \Xibo\Helper\Sanitize::getString('name');

	// You must also provide a duration (all media items must provide this field)
        $this->setDuration(Kit::GetParam('duration', _POST, _INT, $this->getDuration(), false));

        // You can store any additional options for your module using the SetOption method
	$this->SetOption('name', $name);
        $this->SetOption('useDisplayLocation', \Kit::GetParam('useDisplayLocation', _POST, _CHECKBOX));
        $this->SetOption('color', \Kit::GetParam('color', _POST, _STRING, '#000'));
        $this->SetOption('longitude', \Kit::GetParam('longitude', _POST, _DOUBLE));
        $this->SetOption('latitude', \Kit::GetParam('latitude', _POST, _DOUBLE));
        $this->SetOption('templateId', \Kit::GetParam('templateId', _POST, _STRING));
        $this->SetOption('icons', \Kit::GetParam('icons', _POST, _STRING));
        $this->SetOption('overrideTemplate', \Kit::GetParam('overrideTemplate', _POST, _CHECKBOX));
        $this->SetOption('size', \Kit::GetParam('size', _POST, _INT));
        $this->SetOption('units', \Kit::GetParam('units', _POST, _WORD));
        $this->SetOption('lang', \Kit::GetParam('lang', _POST, _WORD));

        $this->setRawNode('styleSheet', \Kit::GetParam('styleSheet', _POST, _HTMLSTRING));
        $this->setRawNode('currentTemplate', \Kit::GetParam('currentTemplate', _POST, _HTMLSTRING));
        $this->setRawNode('dailyTemplate', \Kit::GetParam('dailyTemplate', _POST, _HTMLSTRING));

        // Save the widget
        $this->saveWidget();

        // Load form
        $response->loadForm = true;
        $response->loadFormUri = $this->getTimelineLink();

        return $response;
    }

    /**
     * Set any field dependencies
     * @param ApplicationState $response
     */
    private function SetFieldDepencencies(&$response)
    {
        // Add a dependency
        $locationControls_0 = array(
                '.locationControls' => array('display' => 'block')
            );

        $locationControls_1 = array(
                '.locationControls' => array('display' => 'none')
            );

        $response->AddFieldAction('useDisplayLocation', 'init', false, $locationControls_0, 'is:checked');
        $response->AddFieldAction('useDisplayLocation', 'change', false, $locationControls_0, 'is:checked');
        $response->AddFieldAction('useDisplayLocation', 'init', true, $locationControls_1, 'is:checked');
        $response->AddFieldAction('useDisplayLocation', 'change', true, $locationControls_1, 'is:checked');
        $response->AddFieldAction('templateId', 'init', 'picture', array('.icon-controls' => array('display' => 'block')));
        $response->AddFieldAction('templateId', 'change', 'picture', array('.icon-controls' => array('display' => 'block')));
        $response->AddFieldAction('templateId', 'init', 'picture', array('.icon-controls' => array('display' => 'none')), 'not');
        $response->AddFieldAction('templateId', 'change', 'picture', array('.icon-controls' => array('display' => 'none')), 'not');
        
        // When the override template check box is ticked, we want to expose the advanced controls and we want to hide the template selector
        $response->AddFieldAction('overrideTemplate', 'init', false, 
            array(
                '.template-override-controls' => array('display' => 'none'),
                '.reloadTemplateButton' => array('display' => 'none'),
                '.template-selector-control' => array('display' => 'block')
            ), 'is:checked');
        $response->AddFieldAction('overrideTemplate', 'change', false, 
            array(
                '.template-override-controls' => array('display' => 'none'),
                '.reloadTemplateButton' => array('display' => 'none'),
                '.template-selector-control' => array('display' => 'block')
            ), 'is:checked');
        $response->AddFieldAction('overrideTemplate', 'init', true, 
            array(
                '.template-override-controls' => array('display' => 'block'),
                '.reloadTemplateButton' => array('display' => 'block'),
                '.template-selector-control' => array('display' => 'none')
            ), 'is:checked');
        $response->AddFieldAction('overrideTemplate', 'change', true, 
            array(
                '.template-override-controls' => array('display' => 'block'),
                '.reloadTemplateButton' => array('display' => 'block'),
                '.template-selector-control' => array('display' => 'none')
            ), 'is:checked');
    }

    private function iconsAvailable() 
    {
        // Scan the forecast io folder for icons
        $icons = array();

        foreach (array_diff(scandir($this->resourceFolder), array('..', '.')) as $file) {
            if (stripos($file, '.png'))
                $icons[] = array('id' => $file, 'value' => ucfirst(str_replace('-', ' ', str_replace('.png', '', $file))));
        }

        return $icons;
    }

    /**
     * Units supported by Forecast.IO API
     * @return array The Units Available
     */
    private function unitsAvailable()
    {
        return array(
                array('id' => 'auto', 'value' => 'Automatically select based on geographic location'),
                array('id' => 'ca', 'value' => 'Canada'),
                array('id' => 'si', 'value' => 'Standard International Units'),
                array('id' => 'uk', 'value' => 'United Kingdom'),
                array('id' => 'us', 'value' => 'United States'),
            );
    }

    /**
     * Languages supported by Forecast.IO API
     * @return array The Supported Language
     */
    private function supportedLanguages()
    {
        return array(
            array('id' => 'en', 'value' => __('English')),
            array('id' => 'bs', 'value' => __('Bosnian')),
            array('id' => 'de', 'value' => __('German')),
            array('id' => 'es', 'value' => __('Spanish')),
            array('id' => 'fr', 'value' => __('French')),
            array('id' => 'it', 'value' => __('Italian')),
            array('id' => 'nl', 'value' => __('Dutch')),
            array('id' => 'pl', 'value' => __('Polish')),
            array('id' => 'pt', 'value' => __('Portuguese')),
            array('id' => 'ru', 'value' => __('Russian')),
            array('id' => 'tet', 'value' => __('Tetum')),
            array('id' => 'x-pig-latin', 'value' => __('lgpay Atinlay'))
        );
    }

    // Request content for this tab
    public function requestTab()
    {
        if (!$data = $this->getForecastData(0))
            die(__('No data returned, please check error log.'));

        $cols = array(
                array('name' => 'forecast', 'title' => __('Forecast')),
                array('name' => 'key', 'title' => __('Substitute')),
                array('name' => 'value', 'title' => __('Value'))
            );
        Theme::Set('table_cols', $cols);

        $rows = array();
        foreach ($data['currently'] as $key => $value) {
            if (stripos($key, 'time')) {
                $value = Date::getLocalDate($value);
            }

            $rows[] = array('forecast' => __('Current'), 'key' => $key, 'value' => $value);
        }

        foreach ($data['daily']['data'][0] as $key => $value) {
            if (stripos($key, 'time')) {
                $value = Date::getLocalDate($value);
            }

            $rows[] = array('forecast' => __('Daily'), 'key' => $key, 'value' => $value);
        }

        Theme::Set('table_rows', $rows);
        $this->getState()->html .= Theme::RenderReturn('table_render');
        exit();
    }

    // Get the forecast data for the provided display id
    private function getForecastData($displayId)
    {
        $defaultLat = Config::GetSetting('DEFAULT_LAT');
        $defaultLong = Config::GetSetting('DEFAULT_LONG');

        if ($this->GetOption('useDisplayLocation') == 1) {
            // Use the display ID or the default.
            if ($displayId != 0) {
            
                $display = new Display();
                $display->displayId = $displayId;
                $display->Load();

                $defaultLat = $display->latitude;
                $defaultLong = $display->longitude;
            }
        }
        else {
            $defaultLat = $this->GetOption('latitude', $defaultLat);
            $defaultLong = $this->GetOption('longitude', $defaultLong);
        }

        $apiKey = $this->GetSetting('apiKey');
        if ($apiKey == '')
            die(__('Incorrectly configured module'));

        // Query the API and Dump the Results.
        $forecast = new Forecast($apiKey);

        $apiOptions = array('units' => $this->GetOption('units', 'auto'), 'lang' => $this->GetOption('lang', 'en'), 'exclude' => 'flags,minutely,hourly');
        $key = md5($defaultLat . $defaultLong . 'null' . implode('.', $apiOptions));

        if (!Cache::has($key)) {
            Log::notice('Getting Forecast from the API', $this->getModuleType(), __FUNCTION__);
            if (!$data = $forecast->get($defaultLat, $defaultLong, null, $apiOptions)) {
                return false;
            }

            // If the response is empty, cache it for less time
            $cacheDuration = $this->GetSetting('cachePeriod');

            // Cache
            Cache::put($key, $data, $cacheDuration);
        }
        else {
            Log::notice('Getting Forecast from the Cache with key: ' . $key, $this->getModuleType(), __FUNCTION__);
            $data = Cache::get($key);
        }

        //Debug::Audit('Data: ' . var_export($data, true));

        // Icon Mappings
        $icons = array(
                'unmapped' => 'wi-alien',
                'clear-day' => 'wi-day-sunny',
                'clear-night' => 'wi-night-clear',
                'rain' => 'wi-rain',
                'snow' => 'wi-snow',
                'sleet' => 'wi-hail',
                'wind' => 'wi-windy',
                'fog' => 'wi-fog',
                'cloudy' => 'wi-cloudy',
                'partly-cloudy-day' => 'wi-day-cloudy',
                'partly-cloudy-night' => 'wi-night-partly-cloudy',
            );

        $data->currently->wicon = (isset($icons[$data->currently->icon]) ? $icons[$data->currently->icon] : $icons['unmapped']);
        $data->currently->temperatureFloor = (isset($data->currently->temperature) ? floor($data->currently->temperature) : '--');
        $data->currently->summary = (isset($data->currently->summary) ? $data->currently->summary : '--');
        $data->currently->weekSummary = (isset($data->daily->summary) ? $data->daily->summary : '--');

        // Convert a stdObject to an array
        $data = json_decode(json_encode($data), true);

        // Process the icon for each day
        for ($i = 0; $i < 7; $i++) {
            $data['daily']['data'][$i]['wicon'] = (isset($icons[$data['daily']['data'][$i]['icon']]) ? $icons[$data['daily']['data'][$i]['icon']] : $icons['unmapped']);
            $data['daily']['data'][$i]['temperatureMaxFloor'] = (isset($data['daily']['data'][$i]['temperatureMax'])) ? floor($data['daily']['data'][$i]['temperatureMax']) : '--';
            $data['daily']['data'][$i]['temperatureMinFloor'] = (isset($data['daily']['data'][$i]['temperatureMin'])) ? floor($data['daily']['data'][$i]['temperatureMin']) : '--';
            $data['daily']['data'][$i]['temperatureFloor'] = ($data['daily']['data'][$i]['temperatureMinFloor'] != '--' && $data['daily']['data'][$i]['temperatureMaxFloor'] != '--') ? floor((($data['daily']['data'][$i]['temperatureMinFloor'] + $data['daily']['data'][$i]['temperatureMaxFloor']) / 2)) : '--';
        }

        return $data;
    }

    private function makeSubstitutions($data, $source)
    {
        // Replace all matches.
        $matches = '';
        preg_match_all('/\[.*?\]/', $source, $matches);

        // Substitute
        foreach ($matches[0] as $sub) {
            $replace = str_replace('[', '', str_replace(']', '', $sub));

            // Handling for date/time
            if (stripos($replace, 'time|') > -1) {
                $timeSplit = explode('|', $replace);

                $time = Date::getLocalDate($data['time'], $timeSplit[1]);

                // Pull time out of the array
                $source = str_replace($sub, $time, $source);
            }
            else {
                // Match that in the array
                if (isset($data[$replace]))
                    $source = str_replace($sub, $data[$replace], $source);
            }
        }

        return $source;
    }

    /**
     * Get Resource
     * @param int $displayId
     * @return mixed
     */
    public function GetResource($displayId = 0)
    {
        // Behave exactly like the client.
        if (!$data = $this->getForecastData($displayId))
            return '';

        // A template is provided which contains a number of different libraries that might
        // be useful (jQuery, etc).
        $pathPrefix = (\Kit::GetParam('preview', _REQUEST, _WORD, 'false') == 'true') ? 'modules/theme/forecastio/weather_icons/' : '';
        
        // Get the template
        $template = file_get_contents('modules/preview/HtmlTemplate.html');

        // Replace the View Port Width?
        if (isset($_GET['preview']))
            $template = str_replace('[[ViewPortWidth]]', $this->region->width, $template);

        $headContent = '
            <link href="' . $pathPrefix . 'weather-icons.min.css" rel="stylesheet" media="screen">
            <style type="text/css">
                .container { color: ' . $this->GetOption('color', '000'). '; }
                #content { zoom: ' . $this->GetOption('size', 1). '; }
                ' . $this->getRawNode('styleSheet', null) . '
            </style>
        ';
        
        // Add our fonts.css file
        $isPreview = (\Kit::GetParam('preview', _REQUEST, _WORD, 'false') == 'true');
        $headContent .= '<link href="' . (($isPreview) ? 'modules/preview/' : '') . 'fonts.css" rel="stylesheet" media="screen">';
        $headContent .= '<style type="text/css">' . file_get_contents(Theme::ItemPath('css/client.css')) . '</style>';

        $template = str_replace('<!--[[[HEADCONTENT]]]-->', $headContent, $template);
        
        // Make some body content
        $body = $this->getRawNode('currentTemplate', null);
        $dailyTemplate = $this->getRawNode('dailyTemplate', null);

        // Handle the daily template (if its here)
        if (stripos($body, '[dailyForecast]')) {
            // Pull it out, and run substitute over it for each day
            $dailySubs = '';
            // Substitute for every day (i.e. 7 times).
            for ($i = 0; $i < 7; $i++) {
                $dailySubs .= $this->makeSubstitutions($data['daily']['data'][$i], $dailyTemplate);
            }

            // Substitute the completed template
            $body = str_replace('[dailyForecast]', $dailySubs, $body);
        }

        // Run replace over the main template
        $template = str_replace('<!--[[[BODYCONTENT]]]-->', $this->makeSubstitutions($data['currently'], $body), $template);

        // Replace any icon sets
        $template = str_replace('[[ICONS]]', ((($isPreview) ? 'modules/theme/forecastio/weather_icons/' : '') . $this->GetOption('icons')), $template);
        
        // JavaScript to control the size (override the original width and height so that the widget gets blown up )
        $options = array(
                'previewWidth' => \Kit::GetParam('width', _GET, _DOUBLE, 0),
                'previewHeight' => \Kit::GetParam('height', _GET, _DOUBLE, 0),
                'originalWidth' => $this->region->width,
                'originalHeight' => $this->region->height,
                'scaleOverride' => \Kit::GetParam('scale_override', _GET, _DOUBLE, 0)
            );

        $javaScriptContent  = '<script src="' . (($isPreview) ? 'modules/preview/vendor/' : '') . 'jquery-1.11.1.min.js"></script>';
        $javaScriptContent .= '<script src="' . (($isPreview) ? 'modules/preview/' : '') . 'xibo-layout-scaler.js"></script>';
        $javaScriptContent .= '<script>

            var options = ' . json_encode($options) . '

            $(document).ready(function() {
                $("body").xiboLayoutScaler(options);
            });
        </script>';

        // Replace the After body Content
        $template = str_replace('<!--[[[JAVASCRIPTCONTENT]]]-->', $javaScriptContent, $template);

        // Return that content.
        return $template;
    }
    
    public function GetName()
    {
        return $this->GetOption('name');
    }

    public function IsValid() {
        // Using the information you have in your module calculate whether it is valid or not.
        // 0 = Invalid
        // 1 = Valid
        // 2 = Unknown
        return 1;
    }
}
