<?php
/**
 * Copyright © 2024 All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace SeleneSoftware\MagicCardImporter\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpClient\HttpClient;
use Magento\Framework\App\State;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use SeleneSoftware\MagicCardImporter\Service\CreateCategoryService;
use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Api\AttributeValue;

class Import extends Command
{
    protected static $defaultName = 'magic:import';

    protected $output;

    private $httpClient;
    protected $_state;
    protected $_productFactory;
    protected $_productRepository;
    protected $_productTypeConfigurable;
    protected $_status;
    protected $_attributeRepository;
    protected $_visibility;
    protected $_createCategory;

    public function __construct(
        HttpClient $httpClient,
        State $state,
        \Magento\Catalog\Model\ProductFactory $productFactory,
        \Magento\Catalog\Api\ProductRepositoryInterface $productRepository,
        Configurable $productTypeConfigurable,
        Status $status,
        Visibility $visibility,
        CreateCategoryService $createCategory,
        ProductAttributeRepositoryInterface $attributeRepository,
    ) {
        $this->httpClient = $httpClient;
        $this->_state = $state;
        $this->_productFactory = $productFactory;
        $this->_productRepository = $productRepository;
        $this->_productTypeConfigurable = $productTypeConfigurable;
        $this->_status = $status;
        $this->_visibility = $visibility;
        $this->_createCategory = $createCategory;
        $this->_attributeRepository = $attributeRepository;

        parent::__construct();
    }

    public const SET_ARGUMENT = "set";

    /**
     * {@inheritdoc}
     */
    protected function execute(
        InputInterface $input,
        OutputInterface $output
    ) {
        $name = $input->getArgument(self::SET_ARGUMENT);
        $this->output = $output;
        $this->_state->setAreaCode('adminhtml'); // Set the area code if not set

        if (!$name) {
            $response = $this->httpClient->create()->request(
                'GET',
                'https://api.scryfall.com/sets'
            );

            $content = $response->toArray();
            foreach ($content['data'] as $set) {
                $output->writeln($set['code'] . ' - ' . $set['name']);
            }

            $output->writeln('<error>Please include a Set Code to Import Cards From.</error>');

            return Command::FAILURE;
        }


        $response = $this->httpClient->create()->request(
            'GET',
            'https://api.scryfall.com/sets/'.$name
        );
        // Call it here, create the category
        $category = $this->createCategory($name);
        $this->output->writeln("Category $name has been created.");

        // We get the search uri from the api for a set so we can pull the card data, hence the next request right away.
        $response = $this->httpClient->create()->request(
            'GET',
            $response->toArray()['search_uri']
        );

        foreach ($response->toArray()['data'] as $card) {
            $this->createProduct($card, (int)$category->getId());
            // $this->output->writeln("Card $card['name'] has been imported.");
        }
        return Command::SUCCESS;

    }

    private function createProduct(array $data, int $catid): bool
    {
        if ($data['object'] != 'card') {
            return false;
        }
        // Check if configurable product exists
        $configurableProductSku = "mtg_{$data['set']}_".str_pad($data['collector_number'], 3, '0', STR_PAD_LEFT); // SKU will be in format mtg_set_colnum
        // $configurableProductSku = 'card';
        try {
            // var_dump($configurableProductSku);
            $configurableProduct = $this->_productRepository->get($configurableProductSku);

        } catch (NoSuchEntityException $e) {
            $this->output->writeln("Product $configurableProductSku does not exist.  Creating it now.");
            $configurableProduct = $this->_productFactory->create();
        } catch (\Exception $e) {
            $this->output->writeln('Configurable product already exists.');
            // $this->output->writeln($e->getMessage());
            return false;
        }
        // var_dump(get_class_methods($configurableProduct));
        // var_dump($configurableProduct->getCustomAttributes());
        // die('foop');
        // Create configurable product
        $configurableProduct->setTypeId(Configurable::TYPE_CODE)
                            ->setAttributeSetId($configurableProduct->getDefaultAttributeSetId())
                            ->setSku($configurableProductSku)
                            ->setName($data['name'])
                            ->setPrice(1)
                            ->setStatus(Status::STATUS_ENABLED)
                        // ->setStatus($this->_status->getProductStatus(Status::STATUS_ENABLED))
                            ->setVisibility($this->_visibility->getOptionId(Visibility::VISIBILITY_BOTH))
                            ->setUrlKey(str_replace(' ', '-', $data['name']))
                            ->setStockData(['is_in_stock' => 1, 'qty' => 100])
                            ->setCategoryIds([$catid])
        ;

        // Set other configurable product attributes
        // ...
        // var_dump($data);
        // die('cunt');

        // Associate simple products
        $associatedProducts = [
            'standard',
            'foil',
        ]; // Array of associated simple product SKUs
        foreach ($associatedProducts as $sku) {
            try {
                $simpleProduct = $this->_productRepository->get($configurableProductSku.'-'.$sku);
            } catch (NoSuchEntityException $e) {
                $simpleProduct = $this->_productFactory->create();
            } catch (\Exception $e) {
                $this->output->writeln($e->getMessage());
                return false;
            }
            if ($simpleProduct->getId()) {
                $associatedProducts[] = [
                    'id' => $simpleProduct->getId(),
                    'qty' => 10, // Set quantity for this associated product
                    'attribute_values' => [
                        'type' => $sku,
                    ],
                ];
            }
        }

        if (count($associatedProducts) === 0) {
            $this->output->writeln('Error: No associated products found.');
            return false;
        }
        // var_dump(get_class($configurableProduct));
        // die('balls');

        $attribute = $this->_attributeRepository->get('card_set');
        foreach ($attribute->getOptions() as $opt) {
            if ($opt->getLabel() === $data['set_name']) {
                $value = $opt;
            }
        }
        $configurableAttributesData = [
            'card_set' => [
                'id' =>    $attribute->getAttributeId(),
                'values' => $opt,
            ]
        ]; // Array of configurable attributes and their options
        // var_dump($configurableProduct->getCustomAttributes());
        // var_dump(get_class_methods($configgurableProduct));
        // die('daft cunt');
        $attribute = new AttributeValue();
        $attribute->setAttributeCode('card_set');
        $attribute->setValue($value);
        // Assign associated products to configurable product
        $configurableProduct->setConfigurableProductsData($associatedProducts);
        $configurableProduct->setCustomAttribute($attribute);
        // $configurableProduct->setConfigurableAttributesData($configurableAttributesData);

        try {
            $this->_productRepository->save($configurableProduct);
            $this->output->writeln('Configurable product created successfully.');
        } catch (\Exception $e) {
            $this->output->writeln('Error: ' . $e->getMessage());
        }

        return true;
    }

    protected function getCustomAttribute(string $customAttributeCode): array
    {
        // $customAttributeCode = 'card_set';
        $attribute = $this->_attributeRepository->get($customAttributeCode);
        $customAttributeId = $attribute->getAttributeId();
        // var_dump(get_class_methods($attribute));
        var_dump($attribute->getOptions());
        die('shit');
        return [$customAttributeId];
    }

    protected function createCategory(string $cat)
    {
        $response = $this->httpClient->create()->request(
            'GET',
            'https://api.scryfall.com/sets/'.$cat
        );

        $content = $response->toArray();

        $parent = $this->_createCategory->execute('Magic: the Gathering');
        return $this->_createCategory->execute($content['name'], $parent);
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName("magic:import");
        $this->setDescription("Imports cards from Scryfall");
        $this->setDefinition([
            new InputArgument(self::SET_ARGUMENT, InputArgument::OPTIONAL, "Set Code"),
        ]);
        parent::configure();
    }
}
