<?php
/**
 * Copyright © 2024 All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace SeleneSoftware\MagicCardImporter\Console\Command;

use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\App\State;
use Magento\Framework\Exception\NoSuchEntityException;
use SeleneSoftware\MagicCardImporter\Service\CreateCategoryService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpClient\HttpClient;

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

    public const SET_ARGUMENT = 'set';

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
                $output->writeln($set['code'].' - '.$set['name']);
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

        $this->parseSetData($response->toArray()['search_uri'], $category);

        return Command::SUCCESS;
    }

    private function parseSetData(string $url, Category $category)
    {
        // We get the search uri from the api for a set so we can pull the card data, hence the next request right away.
        $response = $this->httpClient->create()->request(
            'GET',
            $url
            // $response->toArray()['search_uri']
        );

        foreach ($response->toArray()['data'] as $card) {
            $this->createProduct($card, (int) $category->getId());
            // $this->output->writeln("Card $card['name'] has been imported.");
        }

        if ($response->toArray()['has_more']) {
            $this->parseSetData($response->toArray()['next_page'], $category);
        }

        return Command::SUCCESS;

    }

    private function createProduct(array $data, int $catid): bool
    {
        if ('card' != $data['object']) {
            return false;
        }
        // Check if configurable product exists
        $configurableProductSku = "mtg_{$data['set']}_".str_pad($data['collector_number'], 3, '0', STR_PAD_LEFT); // SKU will be in format mtg_set_colnum
        // $configurableProductSku = 'card';
        try {
            $configurableProduct = $this->_productRepository->get($configurableProductSku);

        } catch (NoSuchEntityException $e) {
            $this->output->writeln("Product $configurableProductSku does not exist.  Creating it now.");
            $configurableProduct = $this->_productFactory->create();
        } catch (\Exception $e) {
            $this->output->writeln('Configurable product already exists.');

            // $this->output->writeln($e->getMessage());
            return false;
        }

        // Create configurable product
        $configurableProduct->setTypeId(Configurable::TYPE_CODE)
                            ->setAttributeSetId($configurableProduct->getDefaultAttributeSetId())
                            ->setSku($configurableProductSku)
                            ->setName($data['name'])
                            ->setPrice($data['prices']['usd'])
                            ->setStatus(Status::STATUS_ENABLED)
                            ->setVisibility($this->_visibility->getOptionId(Visibility::VISIBILITY_BOTH))
                            ->setUrlKey(str_replace(' ', '-', $data['name']).'-'.$configurableProductSku)
                            ->setStockData(['is_in_stock' => 1, 'qty' => 100])
                            ->setCategoryIds([$catid])
        ;

        // Set other configurable product attributes
        // ...

        // Associate simple products
        $productTypes = [
            'standard',
            'foil',
        ]; // Array of associated simple product SKUs
        foreach ($productTypes as $sku) {
            try {
                $simpleProduct = $this->_productRepository->get($configurableProductSku.'-'.$sku);
            } catch (NoSuchEntityException $e) {
                $simpleProduct = $this->_productFactory->create();
                $simpleProduct->setSku($configurableProductSku.'-'.$sku)
                              ->setAttributeSetId($configurableProduct->getDefaultAttributeSetId())
                              ->setName($data['name'].' - '.$sku)
                              ->setTypeId('virtual')
                ;
            } catch (\Exception $e) {
                $this->output->writeln($e->getMessage());

                return false;
            }
            $simpleProduct->setPrice(10)
                          ->setCategoryIds([$catid]);
            $simpleProduct->setStockData([
                'qty' => 100,
                'is_in_stock' => 1,
            ]);
            $simpleProduct->save();

            $associatedProducts[] = [
                $simpleProduct->getId(),
                // 'sku' => $configurableProductSku.'-'.$sku,
                // 'qty' => 10, // Set quantity for this associated product
                'attribute_values' => [
                'card_type' => $sku,
                ],
                // 'price' => '100',
            ];

            // $simpleProduct->addData($associatedProducts);
            // $this->_productRepository->save($simpleProduct);
        }

        if (0 === count($associatedProducts)) {
            $this->output->writeln('Error: No associated products found.');

            return false;
        }

        // Assign associated products to configurable product
        $configurableProduct->setConfigurableProductsData($associatedProducts);
        // $configurableProduct->addData($associatedProducts);

        // Damn transform cards
        if ('transform' === $data['layout']) {
            $mana = $data['card_faces'][0]['mana_cost'];
        } else {
            $mana = $data['mana_cost'];
        }
        $configurableProduct->addData([
            'card_set' => $data['set_name'],
            'mana_cost' => $mana, // See Above
            'color_identy' => $data['color_identity'],
            'collector_number' => $data['collector_number'],
            'type_line' => $data['type_line'],
        ]);

        try {
            $this->_productRepository->save($configurableProduct);
            // $this->output->writeln('Configurable product created successfully.');
        } catch (\Exception $e) {
            $this->output->writeln('Error: '.$e->getMessage());
        }

        return true;
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

    protected function configure()
    {
        $this->setName('magic:import');
        $this->setDescription('Imports cards from Scryfall');
        $this->setDefinition([
            new InputArgument(self::SET_ARGUMENT, InputArgument::OPTIONAL, 'Set Code'),
        ]);
        parent::configure();
    }
}
