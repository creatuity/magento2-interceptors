<?php

namespace Creatuity\Interception\Generator;

use Magento\Setup\Module\Di\Compiler\Config\Chain\InterceptorSubstitution;

/**
 * Class CompiledInterceptorSubstitution adds required parameters to interceptor constructor
 */
class CompiledInterceptorSubstitution extends InterceptorSubstitution
{
    /**
     * Modifies input config
     *
     * @param array $config
     * @return array
     */
    public function modify(array $config)
    {
        $config = parent::modify($config);
        foreach ($config['arguments'] as $instanceName => &$arguments) {
            $finalInstance = isset($config['instanceTypes'][$instanceName]) ? $config['instanceTypes'][$instanceName] : $instanceName;
            if (substr($finalInstance, -12) === '\Interceptor') {
                foreach (CompiledInterceptor::propertiesToInjectToConstructor() as  $type => $name) {
                    $preference = isset($config['preferences'][$type]) ? $config['preferences'][$type] : $type;
                    foreach ($arguments as $argument) {
                        if (isset($argument['_i_'])) {
                            //get real type of argument (for cases where there is a virtual type set for injected argument)
                            $argumentType = isset($config['instanceTypes'][$argument['_i_']]) ? $config['instanceTypes'][$argument['_i_']] : $argument['_i_'];
                            if ($argumentType == $preference) {
                                continue 2;
                            }
                        }
                    }
                    $arguments = array_merge([$name => ['_i_' => $preference]], $arguments);
                }

            }
        }
        return $config;
    }
}
