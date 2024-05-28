# Mage2 Module SeleneSoftware MagicCardImporter

    ``selenesoftware/module-magiccardimporter``

 - [Main Functionalities](#markdown-header-main-functionalities)
 - [Installation](#markdown-header-installation)
 - [Configuration](#markdown-header-configuration)
 - [Specifications](#markdown-header-specifications)
 - [Attributes](#markdown-header-attributes)


## Main Functionalities
Import Cards and pricing from Scryfall API

## Installation
\* = in production please use the `--keep-generated` option

### Type 1: Zip file

 - Unzip the zip file in `app/code/SeleneSoftware`
 - Enable the module by running `php bin/magento module:enable SeleneSoftware_MagicCardImporter`
 - Apply database updates by running `php bin/magento setup:upgrade`\*
 - Flush the cache by running `php bin/magento cache:flush`

### Type 2: Composer

 - Make the module available in a composer repository for example:
    - private repository `repo.magento.com`
    - public repository `packagist.org`
    - public github repository as vcs
 - Add the composer repository to the configuration by running `composer config repositories.repo.magento.com composer https://repo.magento.com/`
 - Install the module composer by running `composer require selenesoftware/module-magiccardimporter`
 - enable the module by running `php bin/magento module:enable SeleneSoftware_MagicCardImporter`
 - apply database updates by running `php bin/magento setup:upgrade`\*
 - Flush the cache by running `php bin/magento cache:flush`


## Configuration




## Specifications

 - Console Command
	- import


## Attributes

 - Product - Card Set (card_set)

 - Product - Color Identity (color_identity)

 - Product - Mana Cost (mana_cost)

 - Product - Multiverse ID (multiverse_id)

 - Product - Type Line (type_line)

 - Product - Type (type)

