<?php
namespace OneCatalog\Import\Controller\Adminhtml\Import;

use Magento\Backend\App\Action;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action
{
    public const ADMIN_RESOURCE = 'OneCatalog_Import::import';

    private $resultPageFactory;

    public function __construct(Action\Context $context, PageFactory $resultPageFactory)
    {
        parent::__construct($context);
        $this->resultPageFactory = $resultPageFactory;
    }

    public function execute()
    {
        $page = $this->resultPageFactory->create();
        $page->setActiveMenu('OneCatalog_Import::import');
        $page->getConfig()->getTitle()->prepend(__('OneCatalog — Import products'));
        return $page;
    }
}
