<?php
namespace OneCatalog\Import\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class CollectionTarget implements OptionSourceInterface
{
    public function toOptionArray()
    {
        return [
            ['value' => 'attribute', 'label' => __('Attribute')],
            ['value' => 'category', 'label' => __('Category')],
        ];
    }
}
