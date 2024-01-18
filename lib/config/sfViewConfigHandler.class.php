<?php

/*
 * This file is part of the symfony package.
 * (c) 2004-2006 Fabien Potencier <fabien.potencier@symfony-project.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * sfViewConfigHandler allows you to configure views.
 *
 * @author     Fabien Potencier <fabien.potencier@symfony-project.com>
 */
class sfViewConfigHandler extends sfYamlConfigHandler
{
    /**
     * Executes this configuration handler.
     *
     * @param array $configFiles An array of absolute filesystem path to a configuration file
     *
     * @return string Data to be written to a cache file
     *
     * @throws sfConfigurationException  If a requested configuration file does not exist or is not readable
     * @throws sfParseException          If a requested configuration file is improperly formatted
     * @throws sfInitializationException If a view.yml key check fails
     */
    public function execute($configFiles)
    {
        // parse the yaml
        $this->yamlConfig = static::getConfiguration($configFiles);

        // init our data array
        $data = array();

        $data[] = "\$response = \$this->context->getResponse();\n\n";

        // first pass: iterate through all view names to determine the real view name
        $first = true;
        foreach ($this->yamlConfig as $viewName => $values) {
            if ('all' == $viewName) {
                continue;
            }

            $data[] = ($first ? '' : 'else ')."if (\$this->actionName.\$this->viewName == '{$viewName}')\n".
                      "{\n";
            $data[] = $this->addTemplate($viewName);
            $data[] = "}\n";

            $first = false;
        }

        // general view configuration
        $data[] = ($first ? '' : "else\n{")."\n";
        $data[] = $this->addTemplate($viewName);
        $data[] = ($first ? '' : '}')."\n\n";

        // second pass: iterate through all real view names
        $first = true;
        foreach ($this->yamlConfig as $viewName => $values) {
            if ('all' == $viewName) {
                continue;
            }

            $data[] = ($first ? '' : 'else ')."if (\$templateName.\$this->viewName == '{$viewName}')\n".
                      "{\n";

            $data[] = $this->addLayout($viewName);
            $data[] = $this->addComponentSlots($viewName);
            $data[] = $this->addHtmlHead($viewName);
            $data[] = $this->addEscaping($viewName);

            $data[] = $this->addHtmlAsset($viewName);

            $data[] = "}\n";

            $first = false;
        }

        // general view configuration
        $data[] = ($first ? '' : "else\n{")."\n";

        $data[] = $this->addLayout();
        $data[] = $this->addComponentSlots();
        $data[] = $this->addHtmlHead();
        $data[] = $this->addEscaping();

        $data[] = $this->addHtmlAsset();
        $data[] = ($first ? '' : '}')."\n";

        // compile data
        $retval = sprintf(
            "<?php\n".
                          "// auto-generated by sfViewConfigHandler\n".
                          "// date: %s\n%s\n",
            date('Y/m/d H:i:s'),
            implode('', $data)
        );

        return $retval;
    }

    /**
     * @see sfConfigHandler
     */
    public static function getConfiguration(array $configFiles)
    {
        return static::mergeConfig(static::parseYamls($configFiles));
    }

    /**
     * Adds a component slot statement to the data.
     *
     * @param string $viewName The view name
     *
     * @return string The PHP statement
     */
    protected function addComponentSlots($viewName = '')
    {
        $data = '';

        $components = $this->mergeConfigValue('components', $viewName);
        foreach ($components as $name => $component) {
            if (!is_array($component) || count($component) < 1) {
                $component = array(null, null);
            }

            $data .= "  \$this->setComponentSlot('{$name}', '{$component[0]}', '{$component[1]}');\n";
            $data .= "  if (sfConfig::get('sf_logging_enabled')) \$this->context->getEventDispatcher()->notify(new sfEvent(\$this, 'application.log', array(sprintf('Set component \"%s\" (%s/%s)', '{$name}', '{$component[0]}', '{$component[1]}'))));\n";
        }

        return $data;
    }

    /**
     * Adds a template setting statement to the data.
     *
     * @param string $viewName The view name
     *
     * @return string The PHP statement
     */
    protected function addTemplate($viewName = '')
    {
        $data = '';

        $templateName = $this->getConfigValue('template', $viewName);
        $defaultTemplateName = $templateName ? "'{$templateName}'" : '$this->actionName';

        $data .= "  \$templateName = sfConfig::get('symfony.view.'.\$this->moduleName.'_'.\$this->actionName.'_template', {$defaultTemplateName});\n";
        $data .= "  \$this->setTemplate(\$templateName.\$this->viewName.\$this->getExtension());\n";

        return $data;
    }

    /**
     * Adds a layout statement statement to the data.
     *
     * @param string $viewName The view name
     *
     * @return string The PHP statement
     */
    protected function addLayout($viewName = '')
    {
        // true if the user set 'has_layout' to true or set a 'layout' name for this specific action
        $hasLocalLayout = isset($this->yamlConfig[$viewName]['layout']) || (isset($this->yamlConfig[$viewName]) && array_key_exists('has_layout', $this->yamlConfig[$viewName]));

        // the layout value
        $layout = $this->getConfigValue('has_layout', $viewName) ? $this->getConfigValue('layout', $viewName) : false;

        // the user set a decorator in the action
        $data = <<<'EOF'
  if (null !== $layout = sfConfig::get('symfony.view.'.$this->moduleName.'_'.$this->actionName.'_layout'))
  {
    $this->setDecoratorTemplate(false === $layout ? false : $layout.$this->getExtension());
  }
EOF;

        if ($hasLocalLayout) {
            // the user set a decorator in view.yml for this action
            $data .= <<<EOF

  else
  {
    \$this->setDecoratorTemplate('' == '{$layout}' ? false : '{$layout}'.\$this->getExtension());
  }

EOF;
        } else {
            // no specific configuration
            // set the layout to the 'all' view.yml value except if:
            //   * the decorator template has already been set by "someone" (via view.configure_format for example)
            //   * the request is an XMLHttpRequest request
            $data .= <<<EOF

  else if (null === \$this->getDecoratorTemplate() && !\$this->context->getRequest()->isXmlHttpRequest())
  {
    \$this->setDecoratorTemplate('' == '{$layout}' ? false : '{$layout}'.\$this->getExtension());
  }

EOF;
        }

        return $data;
    }

    /**
     * Adds http metas and metas statements to the data.
     *
     * @param string $viewName The view name
     *
     * @return string The PHP statement
     */
    protected function addHtmlHead($viewName = '')
    {
        $data = array();

        foreach ($this->mergeConfigValue('http_metas', $viewName) as $httpequiv => $content) {
            $data[] = sprintf("  \$response->addHttpMeta('%s', '%s', false);", $httpequiv, str_replace('\'', '\\\'', $content));
        }

        foreach ($this->mergeConfigValue('metas', $viewName) as $name => $content) {
            $data[] = sprintf("  \$response->addMeta('%s', '%s', false, false);", $name, str_replace('\'', '\\\'', preg_replace('/&amp;(?=\w+;)/', '&', htmlspecialchars((string) $content, ENT_QUOTES, sfConfig::get('sf_charset')))));
        }

        return implode("\n", $data)."\n";
    }

    /**
     * Adds stylesheets and javascripts statements to the data.
     *
     * @param string $viewName The view name
     *
     * @return string The PHP statement
     */
    protected function addHtmlAsset($viewName = '')
    {
        // Merge the current view's stylesheets with the app's default stylesheets
        $stylesheets = $this->mergeConfigValue('stylesheets', $viewName);
        $css = $this->addAssets('Stylesheet', $stylesheets);

        // Merge the current view's javascripts with the app's default javascripts
        $javascripts = $this->mergeConfigValue('javascripts', $viewName);
        $js = $this->addAssets('Javascript', $javascripts);

        return implode("\n", array_merge($css, $js))."\n";
    }

    /**
     * Adds an escaping statement to the data.
     *
     * @param string $viewName The view name
     *
     * @return string The PHP statement
     */
    protected function addEscaping($viewName = '')
    {
        $data = array();

        $escaping = $this->getConfigValue('escaping', $viewName);

        if (isset($escaping['method'])) {
            $data[] = sprintf('  $this->getAttributeHolder()->setEscapingMethod(%s);', var_export($escaping['method'], true));
        }

        return implode("\n", $data)."\n";
    }

    protected static function mergeConfig($config)
    {
        // merge javascripts and stylesheets
        $config['all']['stylesheets'] = array_merge(isset($config['default']['stylesheets']) && is_array($config['default']['stylesheets']) ? $config['default']['stylesheets'] : array(), isset($config['all']['stylesheets']) && is_array($config['all']['stylesheets']) ? $config['all']['stylesheets'] : array());
        unset($config['default']['stylesheets']);

        $config['all']['javascripts'] = array_merge(isset($config['default']['javascripts']) && is_array($config['default']['javascripts']) ? $config['default']['javascripts'] : array(), isset($config['all']['javascripts']) && is_array($config['all']['javascripts']) ? $config['all']['javascripts'] : array());
        unset($config['default']['javascripts']);

        // merge default and all
        $config['all'] = sfToolkit::arrayDeepMerge(
            isset($config['default']) && is_array($config['default']) ? $config['default'] : array(),
            isset($config['all']) && is_array($config['all']) ? $config['all'] : array()
        );

        unset($config['default']);

        return static::replaceConstants($config);
    }

    /**
     * Creates a list of add$Type PHP statements for the given type and config.
     *
     * @param string $type   of asset. Requires an sfWebResponse->add$Type(string, string, array) method
     * @param array  $assets
     *
     * @return array ist of add$Type PHP statements
     */
    private function addAssets($type, $assets)
    {
        $tmp = array();
        foreach ((array) $assets as $asset) {
            $position = '';
            if (is_array($asset)) {
                reset($asset);
                $key = key($asset);
                $options = $asset[$key];
                if (isset($options['position'])) {
                    $position = $options['position'];
                    unset($options['position']);
                }
            } else {
                $key = $asset;
                $options = array();
            }

            if ('-*' == $key) {
                $tmp = array();
            } elseif ('-' == $key[0]) {
                unset($tmp[substr($key, 1)]);
            } else {
                $tmp[$key] = sprintf("  \$response->add%s('%s', '%s', %s);", $type, $key, $position, str_replace("\n", '', var_export($options, true)));
            }
        }

        return array_values($tmp);
    }
}
