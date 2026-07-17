<?php

namespace Creatuity\Interception\Generator;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\Config\Scope;

/**
 * Class AreasPluginList
 */
class AreasPluginList
{
    /** @var Scope */
    private $scope;

    /** @var array */
    private $plugins;

    /**
     * AreasPluginList constructor.
     * @param Scope $scope
     * @param array|null $plugins
     */
    public function __construct(
        Scope $scope,
        $plugins = null
    ) {
        $this->scope = $scope;
        $this->plugins = $plugins;
    }

    /**
     * Get array of plugns config indexed by scope code
     *
     * @return array
     */
    public function getPluginsConfigForAllAreas()
    {
        if ($this->plugins === null) {
            $this->plugins = [];
            //this is to emulate order M2 is reading scopes config to use scope cache
            //"global|primary" should be loaded first and then "global|primary|frontend" etc.
            //Always include the "primary" and "global" scopes (regardless of what
            //Scope::getAllScopes() returns on a given Magento version) so that plugins
            //declared in the global etc/di.xml are represented for the default/CLI branch
            //of the generated interceptor and not only in the area specific branches.
            //See https://github.com/creatuity/magento2-interceptors/issues/28
            $scopes = array_values(array_unique(array_merge(['primary', 'global'], $this->scope->getAllScopes())));
            $defaultScopePluginList = $defaultScope = null;
            foreach ($scopes as $scope) {
                if ($defaultScopePluginList === null) {
                    $defaultScopePluginList = new CompiledPluginList(
                        ObjectManager::getInstance(),
                        new StaticScope($scope)
                    );
                    $defaultScopePluginList->getNext('dummy', 'dummy');
                    $defaultScope = $scope;
                } else {
                    $this->plugins[$scope] = clone $defaultScopePluginList;
                    $this->plugins[$scope]->setScope(new StaticScope($scope));
                    //$this->plugins[$scope]->getNext('dummy', 'dummy');
                }
            }
            $this->plugins[$defaultScope] = $defaultScopePluginList;
        }
        return $this->plugins;
    }
}
