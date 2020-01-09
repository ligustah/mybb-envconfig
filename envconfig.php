<?php

// Disallow direct access to this file for security reasons
if (!defined("IN_MYBB")) {
    die("Direct initialization of this file is not allowed.");
}

// use priority 0 for these one so we can adjust settings before other hooks run
$plugins->add_hook('global_start', 'envconfig_start', 0);
$plugins->add_hook('admin_load', 'envconfig_admin_load', 0);

$plugins->add_hook('admin_formcontainer_output_row', 'envconfig_admin_form_overwrite');

$envconfig = array(
    'in_settings' => false,
    'settings' => array(),
);

function envconfig_info()
{
    return array(
        'name' => 'Envconfig',
        'description' => 'Overwrite settings through environment variables',
        'website' => 'https://github.com/ligustah/mybb-envconfig',
        'author' => 'Ligustah',
        'authorsite' => 'https://github.com/ligustah',
        'version' => '1.0',
        'compatibility' => '18*',
        'codename' => 'envconfig'
    );
}

/**
 * Check whether we are on the settings admin module
 */
function envconfig_admin_load()
{
    global $action_file, $run_module, $envconfig;

    // since global_start is not triggered by admin
    // we'll just manually invoke our settings overwrite here
    envconfig_start();

    if ($run_module == 'config' && $action_file == 'settings.php') {
        $envconfig['in_settings'] = true;
    }
}

/**
 * Plugin hook that runs on admin_formcontainer_output_row
 *
 * The purpose of this hook is to replace html input elements on the settings page
 * to make it more visible, which settings are currently being overridden by this plugin.
 *
 * @param $arguments
 */
function envconfig_admin_form_overwrite($arguments)
{
    global $envconfig;

    if ($envconfig['in_settings']) {
        if (!empty($arguments['row_options']['id'])) {
            $row_id = $arguments['row_options']['id'];
            $prefix = 'row_setting_';

            // see if we can find our settings row id prefix and remove it
            if (my_strpos($row_id, $prefix) === 0) {
                $setting_name = my_substr($row_id, strlen($prefix));

                // now see if we have a setting overwrite for this specific setting name
                if (array_key_exists($setting_name, $envconfig['settings'])) {
                    $setting = $envconfig['settings'][$setting_name];

                    // now replace the content with our static setting
                    $arguments['content'] = _envconfig_create_setting_input($setting);
                    $arguments['title'] .= sprintf(
                        ' (overridden by <a href="index.php?module=config-plugins">Envconfig</a> $%s)',
                        $setting['env']
                    );
                }
            }
        }
    }
}

/**
 * Plugin hook that runs on global_start
 *
 * Look at all the mybb settings and see if there are matching environment variables
 * to dynamically overwrite their values.
 */
function envconfig_start()
{
    global $mybb, $envconfig;

    $env = getenv();

    foreach ($mybb->settings as $name => $value) {
        $env_name = _envconfig_name_to_env($name);

        // see if we have an environment variable overwrite for this setting
        if (array_key_exists($env_name, $env)) {
            $env_value = $env[$env_name];
            // change the setting ...
            $mybb->settings[$name] = $env_value;

            // ... and keep track of the original value
            $envconfig['settings'][$name] = array(
                'name' => $name,
                'original' => $value,
                'value' => $env_value,
                'env' => $env_name,
            );

            // replicate some special case handling from inc/init.php
            switch ($name) {
                case 'wolcutoffmins':
                    $mybb->settings['wolcutoff'] = $env_value * 60;
                    break;
                case 'bbname':
                    $mybb->settings['bbname_orig'] = $env_value;
                    $mybb->settings['bbname'] = strip_tags($env_value);
                    break;
                case 'bblanguage':
                    $mybb->settings['orig_bblanguage'] = $env_value;
                    break;
            }
        }
    }
}

/**
 * Generate html code that can be used in place of the default code generated by the settings module
 *
 * @param array $setting a setting from our envconfig cache
 * @return string generated html code
 */
function _envconfig_create_setting_input(array $setting): string
{
    $value = htmlspecialchars_uni($setting['value']);
    $original = htmlspecialchars_uni($setting['original']);
    $title = sprintf('Original value: %s', $original);
    $name = $setting['name'];

    // now replace the content with our static setting
    $hidden_element = '<input type="hidden" name="upsetting[%s]" id="setting_%s" class="text_input" value="%s">';
    $elements = sprintf($hidden_element, $name, $name, $original);

    $visible_element = '<input type="text" title="%s" class="text_input" value="%s" disabled>';
    $elements .= sprintf($visible_element, $title, $value);

    return $elements;
}

/**
 * Get the name of the environment variable that corresponds to a setting name
 *
 * @param string $name setting name
 * @return string the name of the environment variable
 */
function _envconfig_name_to_env(string $name): string
{
    return 'MYBB_SETTINGS_' . strtoupper($name);
}