<?php
namespace OneCatalog\Import\Controller\Adminhtml\B2b;

use Magento\Backend\App\Action;
use Magento\Framework\Controller\Result\JsonFactory;
use OneCatalog\Import\Service\B2bSync;

class Sync extends Action
{
    public const ADMIN_RESOURCE = 'OneCatalog_Import::b2b';

    private $jsonFactory;
    private $sync;

    public function __construct(Action\Context $context, JsonFactory $jsonFactory, B2bSync $sync)
    {
        parent::__construct($context);
        $this->jsonFactory = $jsonFactory;
        $this->sync = $sync;
    }

    public function execute()
    {
        $start = (int) $this->getRequest()->getParam('start', 0);
        $limit = max(1, (int) $this->getRequest()->getParam('limit', 200));
        return $this->jsonFactory->create()->setData($this->sync->processPage($start, $limit));
    }
}
