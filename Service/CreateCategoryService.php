<?php

declare(strict_types=1);

namespace SeleneSoftware\MagicCardImporter\Service;

use Magento\Store\Model\StoreManagerInterface;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;
use Magento\Catalog\Model\CategoryRepository;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Exception\CouldNotSaveException;
use Psr\Log\LoggerInterface;

class CreateCategoryService
{
    protected $storeManager;

    protected $categoryCollectionFactory;

    protected $categoryRepository;

    protected $logger;

    public function __construct(
        StoreManagerInterface $storeManager,
        CollectionFactory $categoryCollectionFactory,
        CategoryRepository $categoryRepository,
        LoggerInterface $logger
    ) {
        $this->storeManager = $storeManager;
        $this->categoryCollectionFactory = $categoryCollectionFactory;
        $this->categoryRepository = $categoryRepository;
        $this->logger = $logger;
    }

    public function execute(string $categoryName, $parentCat = null)
    {
        $category = $this->categoryCollectionFactory->create()
          ->addAttributeToFilter('name', $categoryName)
          ->getFirstItem();

        if ($category->getId()) {
            // var_dump($category);
            // var_dump(get_class_methods($category));
            // var_dump($category->getParentCategory());
            return $category;
        }


        try {
            if (!is_null($parentCat)) {
                $parentId = $parentCat->getId();
                $parentCategory = $parentCat;
            } else {
                $parentId = $this->storeManager->getStore()->getRootCategoryId();
                $parentCategory = $this->categoryRepository->get($parentId);
            }
        } catch (NoSuchEntityException $noSuchEntityException) {
            $this->logger->error('This is an error while getting rootCategory');
            return $category;
        }

        $category->setPath($parentCategory->getPath())
          ->setParentId($parentId)
          ->setName($categoryName)
          ->setIsActive(true);


        try {
            $this->categoryRepository->save($category);
        } catch (CouldNotSaveException $couldNotSaveException) {
            $this->logger->error('This is an error while saving category');
            return $category;
        }

        // This is supposed to be where I can move the category under the Magic: the Gathering main category, but I can't get it to work.
        // If you can, submit a pull request and we can talk.
        // if (!is_null($parentCat)) {
        //     $category->move($parentId, $category->getId());
        // }
        return $category;
    }
}
