<?php
namespace OneCatalog\Import\Controller\Adminhtml\Import;

use Magento\Backend\App\Action;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\JsonFactory;
use OneCatalog\Import\Service\Importer;

class Batch extends Action
{
    public const ADMIN_RESOURCE = 'OneCatalog_Import::import';

    private $jsonFactory;
    private $importer;
    private $resource;

    public function __construct(
        Action\Context $context,
        JsonFactory $jsonFactory,
        Importer $importer,
        ResourceConnection $resource
    ) {
        parent::__construct($context);
        $this->jsonFactory = $jsonFactory;
        $this->importer = $importer;
        $this->resource = $resource;
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();
        $ids = (array) $this->getRequest()->getParam('ids', []);
        $ids = array_values(array_filter(array_map('trim', $ids)));

        $results = [];
        foreach ($ids as $publicId) {
            try {
                $r = $this->importer->importByPublicId($publicId);
            } catch (\Throwable $e) {
                $r = ['status' => 'error', 'public_id' => $publicId, 'message' => $e->getMessage()];
            }
            $results[] = $r;
            $this->logResult($r);
        }
        return $result->setData(['results' => $results, 'log' => $this->recentLog()]);
    }

    private function logResult(array $r)
    {
        $conn = $this->resource->getConnection();
        $conn->insert($this->resource->getTableName('onecatalog_log'), [
            'public_id' => (string) ($r['public_id'] ?? ''),
            'status' => (string) ($r['status'] ?? ''),
            'message' => (string) ($r['message'] ?? ''),
        ]);
    }

    private function recentLog()
    {
        $conn = $this->resource->getConnection();
        $t = $this->resource->getTableName('onecatalog_log');
        $rows = $conn->fetchAll('SELECT public_id, status, message FROM ' . $t . ' ORDER BY id_log DESC LIMIT 50');
        return array_reverse(is_array($rows) ? $rows : []);
    }
}
