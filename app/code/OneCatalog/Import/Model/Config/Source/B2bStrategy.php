<?php
namespace OneCatalog\Import\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class B2bStrategy implements OptionSourceInterface
{
    public function toOptionArray()
    {
        return [
            ['value' => 'min', 'label' => __('Minimum price')],
            ['value' => 'priority', 'label' => __('By supplier priority')],
            ['value' => 'supplier', 'label' => __('Fixed supplier')],
        ];
    }
}
