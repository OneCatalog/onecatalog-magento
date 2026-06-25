<?php
namespace OneCatalog\Import\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Framework\App\ResourceConnection;

class Log extends Template
{
    private $resource;

    public function __construct(Template\Context $context, ResourceConnection $resource, array $data = [])
    {
        parent::__construct($context, $data);
        $this->resource = $resource;
    }

    public function getEntries()
    {
        $conn = $this->resource->getConnection();
        $t = $this->resource->getTableName('onecatalog_log');
        $rows = $conn->fetchAll('SELECT public_id, status, message, created_at FROM ' . $t . ' ORDER BY id_log DESC LIMIT 200');
        return is_array($rows) ? $rows : [];
    }
}
