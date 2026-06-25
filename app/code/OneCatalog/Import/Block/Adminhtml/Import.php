<?php
namespace OneCatalog\Import\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Framework\Data\Form\FormKey;

class Import extends Template
{
    private $ocFormKey;

    public function __construct(Template\Context $context, FormKey $formKey, array $data = [])
    {
        parent::__construct($context, $data);
        $this->ocFormKey = $formKey;
    }

    public function isConfigured()
    {
        return (string) $this->_scopeConfig->getValue('onecatalog/general/api_token') !== '';
    }

    public function jsUrl($file)
    {
        return $this->getViewFileUrl('OneCatalog_Import::js/' . $file);
    }

    public function getCfgJson()
    {
        $cfg = [
            'ajaxUrl' => $this->getUrl('onecatalog/import/batch'),
            'pickerBase' => (string) ($this->_scopeConfig->getValue('onecatalog/general/picker_base') ?: 'https://tools.onecatalog.net'),
            'token' => (string) $this->_scopeConfig->getValue('onecatalog/general/api_token'),
            'step' => max(10, (int) $this->_scopeConfig->getValue('onecatalog/general/step')),
            'formKey' => $this->ocFormKey->getFormKey(),
            'messages' => [
                'empty' => (string) __('The identifier list is empty'),
                'importing' => (string) __('Importing…'),
                'done' => (string) __('Done:'),
                'error' => (string) __('Error'),
                'cancelled' => (string) __('Cancelled:'),
                'created' => (string) __('Created'),
                'updated' => (string) __('Updated'),
                'errors' => (string) __('Errors'),
                'last' => (string) __('Last result'),
            ],
        ];
        return json_encode($cfg);
    }
}
