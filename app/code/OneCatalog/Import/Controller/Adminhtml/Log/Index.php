<?php
namespace OneCatalog\Import\Controller\Adminhtml\Log;

use Magento\Backend\App\Action;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action
{
    public const ADMIN_RESOURCE = 'OneCatalog_Import::log';

    private $resultPageFactory;

    public function __construct(Action\Context $context, PageFactory $resultPageFactory)
    {
        parent::__construct($context);
        $this->resultPageFactory = $resultPageFactory;
    }

    public function execute()
    {
        $page = $this->resultPageFactory->create();
        $page->setActiveMenu('OneCatalog_Import::log');
        $page->getConfig()->getTitle()->prepend(__('OneCatalog — Import log'));
        return $page;
    }
}
