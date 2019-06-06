<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
// @codingStandardsIgnoreFile
namespace Creatuity\Interception\Test\Integration\CompiledInterceptor\Custom\Module\Model\Item;

class Enhanced extends \Creatuity\Interception\Test\Integration\CompiledInterceptor\Custom\Module\Model\Item
{
    /**
     * @return string
     */
    public function getName()
    {
        return ucfirst(parent::getName());
    }
}
