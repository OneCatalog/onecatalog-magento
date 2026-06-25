<?php
namespace OneCatalog\Import\Controller\Adminhtml\B2b;

use Magento\Backend\App\Action;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action
{
    public const ADMIN_RESOURCE = 'OneCatalog_Import::b2b';

    private $resultPageFactory;

    public function __construct(Action\Context $context, PageFactory $resultPageFactory)
    {
        parent::__construct($context);
        $this->resultPageFactory = $resultPageFactory;
    }

    public function execute()
    {
        $page = $this->resultPageFactory->create();
        $page->setActiveMenu('OneCatalog_Import::b2b');
        $page->getConfig()->getTitle()->prepend(__('OneCatalog — Prices & stock'));
        return $page;
    }
}
