<?php
/**
 * OneCatalog Import — ядро импорта одного товара (Magento 2.4.x, §5).
 *
 * Идемпотентность по public_id через onecatalog_map (не по sku, §5.1). Товар —
 * ProductRepository; категории — find-or-create; характеристики/габариты → EAV-атрибуты
 * (find-or-create в default attribute set). §5.6: цена 0 и статус/sku — только при
 * создании; цена не синтезируется. Единицы — Units.
 */
namespace OneCatalog\Import\Service;

use Magento\Catalog\Api\Data\ProductInterfaceFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Model\CategoryFactory;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Eav\Api\AttributeOptionManagementInterface;
use Magento\Eav\Api\Data\AttributeOptionInterfaceFactory;
use Magento\Eav\Api\Data\AttributeOptionLabelInterfaceFactory;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;

class Importer
{
    private $productRepository;
    private $productFactory;
    private $categoryRepository;
    private $categoryFactory;
    private $categoryCollectionFactory;
    private $storeManager;
    private $eavConfig;
    private $eavSetupFactory;
    private $moduleDataSetup;
    private $attributeRepository;
    private $resource;
    private $scopeConfig;
    private $mediaStore;
    private $optionManagement;
    private $optionFactory;
    private $optionLabelFactory;
    private $eventManager;

    /** @var array<string,string> кэш существующих кодов атрибутов */
    private $attrCache = [];

    public function __construct(
        ProductRepositoryInterface $productRepository,
        ProductInterfaceFactory $productFactory,
        CategoryRepositoryInterface $categoryRepository,
        CategoryFactory $categoryFactory,
        CategoryCollectionFactory $categoryCollectionFactory,
        StoreManagerInterface $storeManager,
        EavConfig $eavConfig,
        EavSetupFactory $eavSetupFactory,
        ModuleDataSetupInterface $moduleDataSetup,
        AttributeRepositoryInterface $attributeRepository,
        ResourceConnection $resource,
        ScopeConfigInterface $scopeConfig,
        MediaStore $mediaStore,
        AttributeOptionManagementInterface $optionManagement,
        AttributeOptionInterfaceFactory $optionFactory,
        AttributeOptionLabelInterfaceFactory $optionLabelFactory,
        EventManager $eventManager
    ) {
        $this->productRepository = $productRepository;
        $this->productFactory = $productFactory;
        $this->categoryRepository = $categoryRepository;
        $this->categoryFactory = $categoryFactory;
        $this->categoryCollectionFactory = $categoryCollectionFactory;
        $this->storeManager = $storeManager;
        $this->eavConfig = $eavConfig;
        $this->eavSetupFactory = $eavSetupFactory;
        $this->moduleDataSetup = $moduleDataSetup;
        $this->attributeRepository = $attributeRepository;
        $this->resource = $resource;
        $this->scopeConfig = $scopeConfig;
        $this->mediaStore = $mediaStore;
        $this->optionManagement = $optionManagement;
        $this->optionFactory = $optionFactory;
        $this->optionLabelFactory = $optionLabelFactory;
        $this->eventManager = $eventManager;
    }

    public function importByPublicId($publicId)
    {
        $payload = $this->api()->getProduct($publicId);
        if ($payload === null) {
            return ['status' => 'error', 'public_id' => $publicId, 'message' => 'product not found in API'];
        }
        return $this->importPayload($payload, $publicId);
    }

    public function importPayload(array $p, $publicId = null)
    {
        $publicId = (string) ($publicId !== null ? $publicId : ($p['public_id'] ?? ''));
        if ($publicId === '') {
            return ['status' => 'error', 'public_id' => '', 'message' => 'empty public_id'];
        }

        $name = trim((string) ($p['name'] ?? $p['title'] ?? $p['menutitle'] ?? ''));
        $description = (string) ($p['description_text'] ?? $p['description'] ?? '');
        $article = trim((string) ($p['article'] ?? ''));
        $dim = $this->resolveDimensions($p);

        $existingId = $this->mapGet($publicId);

        try {
            $isNew = false;
            $product = null;
            if ($existingId) {
                try {
                    $product = $this->productRepository->getById((int) $existingId);
                } catch (\Throwable $e) {
                    $product = null;
                }
            }
            if (!$product) {
                $isNew = true;
                $product = $this->productFactory->create();
                $product->setTypeId('simple');
                $product->setAttributeSetId($this->defaultAttributeSetId());
                $product->setSku($article !== '' ? $article : ('OC-' . $publicId));
                $product->setPrice(0); // §5.6 — не синтезируем
                $product->setStatus(((int) $this->scopeConfig->getValue('onecatalog/general/new_active') === 0) ? Status::STATUS_DISABLED : Status::STATUS_ENABLED);
                $product->setVisibility(Visibility::VISIBILITY_BOTH);
            }

            if ($name !== '') {
                $product->setName($name);
            } elseif ($isNew) {
                return ['status' => 'error', 'public_id' => $publicId, 'message' => 'empty product name'];
            }
            $product->setCustomAttribute('description', $description);
            if ($dim['weight'] !== null) {
                $product->setWeight((float) $dim['weight']);
            }
            foreach (['oc_length' => $dim['length'], 'oc_width' => $dim['width'], 'oc_height' => $dim['height']] as $code => $val) {
                if ($val !== null) {
                    $this->ensureAttribute($code, ucfirst(str_replace('oc_', '', $code)));
                    $product->setCustomAttribute($code, (string) $val);
                }
            }

            // Характеристики → EAV-атрибуты (text), find-or-create по метке.
            foreach ($this->resolveFeatures($p) as $f) {
                $this->ensureAttribute($f['code'], $f['label']);
                $product->setCustomAttribute($f['code'], $f['value']);
            }

            // Категории + справочные сущности (§3/§7, по умолчанию выкл).
            $catIds = $this->resolveCategories($p);
            $this->applyReferences($product, $p, $catIds);
            if ($catIds) {
                $product->setCategoryIds($catIds);
            }

            // Медиа: обложка + галерея (дедуп + трекинг качества, §5.3).
            $priorSig = $existingId ? $this->metaGet((int) $existingId, 'media_sig') : '';
            $mediaSig = $this->mediaStore->applyMediaToProduct($product, $p, $priorSig);

            $saved = $this->productRepository->save($product);
            $id = (int) $saved->getId();
            if ($isNew || !$existingId) {
                $this->mapSet($id, $publicId);
            }
            if ($mediaSig !== null) {
                $this->metaSet($id, 'media_sig', $mediaSig);
            }

            // Событие §8 — сайтовый слой дозаполняет поля, не входящие в ядро.
            $this->eventManager->dispatch('onecatalog_product_imported', [
                'id_product' => $id, 'public_id' => $publicId, 'status' => $isNew ? 'created' : 'updated', 'payload' => $p,
            ]);

            return ['status' => $isNew ? 'created' : 'updated', 'public_id' => $publicId, 'id_product' => $id];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'public_id' => $publicId, 'message' => $e->getMessage()];
        }
    }

    // --- характеристики ------------------------------------------------------

    private function resolveFeatures(array $p)
    {
        $options = $p['options'] ?? null;
        if (!is_array($options)) {
            return [];
        }
        $out = [];
        foreach ($options as $opt) {
            if (!is_array($opt)) {
                continue;
            }
            $label = trim((string) ($opt['specification_label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $type = (string) ($opt['specification_type'] ?? 'text');
            if ($type === 'numeric') {
                $num = $opt['numeric_option'] ?? null;
                $val = ($num === null || $num === '') ? '' : (string) $num;
            } elseif ($type === 'boolean') {
                $val = (($opt['bool_option'] ?? null) === true) ? 'Yes' : 'No';
            } else {
                $val = trim((string) ($opt['specification_option_name'] ?? ''));
            }
            if ($val === '') {
                continue;
            }
            $out[] = ['code' => $this->attrCode($label), 'label' => $label, 'value' => $val];
        }
        return $out;
    }

    private function attrCode($label)
    {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $this->translit($label)));
        $slug = trim($slug, '_');
        if ($slug === '') {
            $slug = 'attr_' . substr(md5($label), 0, 8);
        }
        return substr('oc_' . $slug, 0, 60);
    }

    private function translit($s)
    {
        // грубая транслитерация для кода атрибута (значение пишется как есть)
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT', (string) $s);
        return $t !== false ? $t : (string) $s;
    }

    private function ensureAttribute($code, $label)
    {
        if (isset($this->attrCache[$code])) {
            return;
        }
        try {
            $this->attributeRepository->get(Product::ENTITY, $code);
            $this->attrCache[$code] = $code;
            return;
        } catch (\Throwable $e) {
            // нет — создаём
        }
        try {
            $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);
            $eavSetup->addAttribute(Product::ENTITY, $code, [
                'type' => 'varchar',
                'label' => $label,
                'input' => 'text',
                'required' => 0,
                'user_defined' => 1,
                'global' => ScopedAttributeInterface::SCOPE_GLOBAL,
                'visible' => 1,
                'searchable' => 0,
                'filterable' => 0,
                'comparable' => 0,
                'is_used_in_grid' => 0,
                'group' => 'OneCatalog',
                'apply_to' => '',
            ]);
            $this->eavConfig->clear();
            $this->attrCache[$code] = $code;
        } catch (\Throwable $e) {
            // если не удалось создать — пропускаем характеристику (не валим импорт §5.5)
        }
    }

    // --- справочные сущности (§3/§7: нативное прежде своего, по умолчанию выкл) -

    private function applyReferences($product, array $p, array &$catIds)
    {
        // Бренд → нативный атрибут manufacturer (select), find-or-create опции.
        if ($this->refEnabled('import_brand')) {
            $brand = trim((string) ($p['brand']['menutitle'] ?? $p['brand']['name'] ?? ''));
            if ($brand !== '') {
                $optId = $this->ensureManufacturerOption($brand);
                if ($optId) {
                    $product->setData('manufacturer', $optId);
                }
            }
        }
        // Теги → атрибут oc_tags (в Magento 2 нет нативных тегов).
        if ($this->refEnabled('import_tags') && is_array($p['tags'] ?? null)) {
            $names = [];
            foreach ($p['tags'] as $t) {
                $n = trim((string) (is_array($t) ? ($t['title'] ?? $t['name'] ?? '') : $t));
                if ($n !== '') {
                    $names[$n] = $n;
                }
            }
            if ($names) {
                $this->ensureAttribute('oc_tags', 'Tags');
                $product->setCustomAttribute('oc_tags', implode(', ', array_values($names)));
            }
        }
        // Страна → атрибут oc_country.
        if ($this->refEnabled('import_country')) {
            $country = trim((string) ($p['country']['menutitle'] ?? $p['country']['name'] ?? ''));
            if ($country !== '') {
                $this->ensureAttribute('oc_country', 'Country');
                $product->setCustomAttribute('oc_country', $country);
            }
        }
        // Коллекции → атрибут oc_collection ИЛИ категории (выбор цели).
        if ($this->refEnabled('import_collections') && is_array($p['collections'] ?? null)) {
            $names = [];
            foreach ($p['collections'] as $c) {
                $n = trim((string) (is_array($c) ? ($c['menutitle'] ?? $c['name'] ?? '') : $c));
                if ($n !== '') {
                    $names[$n] = $n;
                }
            }
            if ($names) {
                $target = (string) ($this->scopeConfig->getValue('onecatalog/references/collection_target') ?: 'feature');
                if ($target === 'category') {
                    $rootId = (int) $this->storeManager->getStore()->getRootCategoryId();
                    foreach ($names as $n) {
                        $cid = $this->ensureCategory($n, $rootId);
                        if ($cid) {
                            $catIds[] = $cid;
                        }
                    }
                    $catIds = array_values(array_unique(array_map('intval', $catIds)));
                } else {
                    $this->ensureAttribute('oc_collection', 'Collection');
                    $product->setCustomAttribute('oc_collection', implode(', ', array_values($names)));
                }
            }
        }
    }

    private function ensureManufacturerOption($label)
    {
        try {
            $attr = $this->eavConfig->getAttribute(Product::ENTITY, 'manufacturer');
            if (!$attr || !$attr->getId()) {
                return null;
            }
            $optId = $attr->getSource()->getOptionId($label);
            if ($optId) {
                return $optId;
            }
            $optionLabel = $this->optionLabelFactory->create();
            $optionLabel->setStoreId(0);
            $optionLabel->setLabel($label);
            $option = $this->optionFactory->create();
            $option->setLabel($label);
            $option->setStoreLabels([$optionLabel]);
            $option->setSortOrder(0);
            $option->setIsDefault(false);
            $this->optionManagement->add(Product::ENTITY, 'manufacturer', $option);
            $this->eavConfig->clear();
            $attr = $this->eavConfig->getAttribute(Product::ENTITY, 'manufacturer');
            return $attr->getSource()->getOptionId($label) ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function refEnabled($key)
    {
        return (int) $this->scopeConfig->getValue('onecatalog/references/' . $key) === 1;
    }

    // --- категории -----------------------------------------------------------

    private function resolveCategories(array $p)
    {
        $cats = $p['categories'] ?? null;
        if (!is_array($cats) || !$cats) {
            return [];
        }
        $rootId = (int) $this->storeManager->getStore()->getRootCategoryId();
        $leaves = [];
        foreach ($cats as $cat) {
            if (!is_array($cat)) {
                continue;
            }
            $parentId = $rootId;
            $leafId = 0;
            foreach ($this->categoryChain($cat) as $node) {
                $title = trim((string) ($node['menutitle'] ?? $node['name'] ?? ''));
                if ($title === '') {
                    continue;
                }
                $leafId = $this->ensureCategory($title, $parentId);
                if (!$leafId) {
                    break;
                }
                $parentId = $leafId;
            }
            if ($leafId) {
                $leaves[$leafId] = $leafId;
            }
        }
        return array_values($leaves);
    }

    private function categoryChain(array $start)
    {
        $chain = [];
        $seen = [];
        $node = $start;
        while (is_array($node)) {
            $id = (int) ($node['id'] ?? 0);
            if ($id !== 0) {
                if (isset($seen[$id])) {
                    break;
                }
                $seen[$id] = true;
            }
            array_unshift($chain, $node);
            $parent = $node['parent_category'] ?? null;
            $node = is_array($parent) ? $parent : null;
        }
        return $chain;
    }

    private function ensureCategory($name, $parentId)
    {
        $collection = $this->categoryCollectionFactory->create();
        $collection->addAttributeToFilter('parent_id', (int) $parentId);
        $collection->addAttributeToFilter('name', $name);
        $collection->setPageSize(1);
        $existing = $collection->getFirstItem();
        if ($existing && $existing->getId()) {
            return (int) $existing->getId();
        }
        try {
            $parent = $this->categoryRepository->get((int) $parentId);
            $category = $this->categoryFactory->create();
            $category->setName($name);
            $category->setParentId((int) $parentId);
            $category->setPath($parent->getPath());
            $category->setIsActive(true);
            $category->setAvailableSortBy('position');
            $category->setDefaultSortBy('position');
            $this->categoryRepository->save($category);
            return (int) $category->getId();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    // --- габариты (Units → кг / ед. длины) -----------------------------------

    private function resolveDimensions(array $p)
    {
        $sizes = is_array($p['sizes'] ?? null) ? $p['sizes'] : [];
        $g = $this->baseValue($sizes, ['weight'], 'weight_unit', 'weight');
        $l = $this->baseValue($sizes, ['length'], 'length_unit', 'length');
        $w = $this->baseValue($sizes, ['width'], 'length_unit', 'length');
        $h = $this->baseValue($sizes, ['height', 'thickness'], 'length_unit', 'length');
        return [
            'weight' => $g === null ? null : Units::weight($g, 'kg'),
            'length' => $l === null ? null : Units::length($l, 'cm'),
            'width' => $w === null ? null : Units::length($w, 'cm'),
            'height' => $h === null ? null : Units::length($h, 'cm'),
        ];
    }

    private function baseValue(array $sizes, array $keys, $unitKey, $kind)
    {
        foreach ($keys as $k) {
            if (isset($sizes[$k]) && $sizes[$k] !== '' && is_numeric($sizes[$k])) {
                $val = (float) $sizes[$k];
                $unit = (string) ($sizes[$unitKey] ?? '');
                if ($unit !== '') {
                    return $kind === 'weight' ? Units::toBaseWeight($val, $unit) : Units::toBaseLength($val, $unit);
                }
                return $val;
            }
        }
        return null;
    }

    // --- инфраструктура ------------------------------------------------------

    private function defaultAttributeSetId()
    {
        return (int) $this->eavConfig->getEntityType(Product::ENTITY)->getDefaultAttributeSetId();
    }

    private function mapGet($publicId)
    {
        $conn = $this->resource->getConnection();
        $t = $this->resource->getTableName('onecatalog_map');
        $id = $conn->fetchOne('SELECT entity_id FROM ' . $t . ' WHERE public_id = ?', [$publicId]);
        return $id ? (int) $id : null;
    }

    private function mapSet($entityId, $publicId)
    {
        $conn = $this->resource->getConnection();
        $t = $this->resource->getTableName('onecatalog_map');
        $conn->insertOnDuplicate($t, ['entity_id' => (int) $entityId, 'public_id' => (string) $publicId], ['public_id']);
    }

    private function metaGet($entityId, $key)
    {
        $conn = $this->resource->getConnection();
        $t = $this->resource->getTableName('onecatalog_meta');
        return (string) $conn->fetchOne('SELECT value FROM ' . $t . ' WHERE entity_id = ? AND meta_key = ?', [(int) $entityId, $key]);
    }

    private function metaSet($entityId, $key, $value)
    {
        $conn = $this->resource->getConnection();
        $t = $this->resource->getTableName('onecatalog_meta');
        $conn->insertOnDuplicate($t, ['entity_id' => (int) $entityId, 'meta_key' => (string) $key, 'value' => (string) $value], ['value']);
    }

    private function api()
    {
        return new Api(
            (string) $this->scopeConfig->getValue('onecatalog/general/api_base'),
            (string) $this->scopeConfig->getValue('onecatalog/general/api_token'),
            (string) ($this->scopeConfig->getValue('onecatalog/general/lang') ?: 'en')
        );
    }
}
