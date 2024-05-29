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

class Import extends Command
{
    protected static $defaultName = 'example:httpRequest';

    protected $output;

    private $httpClient;
    protected $_state;
    protected $_productFactory;
    protected $_productRepository;
    protected $_productTypeConfigurable;
    protected $_status;
    protected $_visibility;

    public function __construct(
        HttpClient $httpClient,
        State $state,
        \Magento\Catalog\Model\ProductFactory $productFactory,
        \Magento\Catalog\Api\ProductRepositoryInterface $productRepository,
        Configurable $productTypeConfigurable,
        Status $status,
        Visibility $visibility
    ) {
        $this->httpClient = $httpClient;
        $this->_state = $state;
        $this->_productFactory = $productFactory;
        $this->_productRepository = $productRepository;
        $this->_productTypeConfigurable = $productTypeConfigurable;
        $this->_status = $status;
        $this->_visibility = $visibility;

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
        // We get the search uri from the api for a set so we can pull the card data, hence the next request right away.
        $response = $this->httpClient->create()->request(
            'GET',
            $response->toArray()['search_uri']
        );

        foreach ($response->toArray()['data'] as $card) {
            $this->createProduct($card);
        }
        return Command::SUCCESS;

    }

    private function createProduct(array $data): bool
    {
        $this->_state->setAreaCode('adminhtml'); // Set the area code if not set
        if ($data['object'] != 'card') {
            return false;
        }
        // Check if configurable product exists
        $configurableProductSku = "mtg_{$data['set']}_".str_pad($data['collector_number'], 3, '0', STR_PAD_LEFT); // SKU will be in format mtg_set_colnum

        try {
            $configurableProduct = $this->_productRepository->get($configurableProductSku);
        } catch (\Exception $e) {
            $this->output->writeln('Configurable product already exists.');
            return false;
        }

        // Create configurable product
        $configurableProduct = $this->_productFactory->create();
        $configurableProduct->setTypeId(Configurable::TYPE_CODE)
            ->setAttributeSetId($configurableProduct->getDefaultAttributeSetId())
            ->setSku($configurableProductSku)
            ->setName($data['name'])
            ->setPrice(1)
            ->setStatus($this->_status->getProductStatus(Status::STATUS_ENABLED))
            ->setVisibility($this->_visibility->getOptionId(Visibility::VISIBILITY_BOTH))
            ->setUrlKey('configurable-product')
            ->setStockData(['is_in_stock' => 1, 'qty' => 100]);

        // Set other configurable product attributes
        // ...

        // Associate simple products
        $associatedProducts = []; // Array of associated simple product SKUs
        foreach ($associatedProducts as $sku) {
            $simpleProduct = $this->_productRepository->get($sku);
            if ($simpleProduct->getId()) {
                $associatedProducts[] = [
                    'id' => $simpleProduct->getId(),
                    'qty' => 10, // Set quantity for this associated product
                    'attribute_values' => [], // Array of attribute values for this associated product
                ];
            }
        }

        if (count($associatedProducts) === 0) {
            $output->writeln('Error: No associated products found.');
            return false;
        }

        $configurableAttributesData = []; // Array of configurable attributes and their options

        // Assign associated products to configurable product
        $configurableProduct->setConfigurableProductsData($associatedProducts);
        $configurableProduct->setConfigurableAttributesData($configurableAttributesData);

        try {
            $this->_productRepository->save($configurableProduct);
            $output->writeln('Configurable product created successfully.');
        } catch (\Exception $e) {
            $output->writeln('Error: ' . $e->getMessage());
        }
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
