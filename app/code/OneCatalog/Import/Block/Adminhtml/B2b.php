<?php
namespace OneCatalog\Import\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Framework\Data\Form\FormKey;

class B2b extends Template
{
    private $ocFormKey;

    public function __construct(Template\Context $context, FormKey $formKey, array $data = [])
    {
        parent::__construct($context, $data);
        $this->ocFormKey = $formKey;
    }

    public function isConfigured()
    {
        return (string) $this->_scopeConfig->getValue('onecatalog/b2b/url_key') !== ''
            && (string) $this->_scopeConfig->getValue('onecatalog/b2b/private_key') !== '';
    }

    public function jsUrl($file)
    {
        return $this->getViewFileUrl('OneCatalog_Import::js/' . $file);
    }

    public function configUrl()
    {
        return $this->getUrl('adminhtml/system_config/edit', ['section' => 'onecatalog']);
    }

    public function getCfgJson()
    {
        $cfg = [
            'syncUrl' => $this->getUrl('onecatalog/b2b/sync'),
            'limit' => 200,
            'formKey' => $this->ocFormKey->getFormKey(),
            'messages' => [
                'running' => (string) __('Syncing…'),
                'done' => (string) __('Done:'),
                'error' => (string) __('Error'),
                'changed' => (string) __('Changed'),
                'unchanged' => (string) __('Unchanged'),
                'missing' => (string) __('Not in catalog'),
            ],
        ];
        return json_encode($cfg);
    }
}
