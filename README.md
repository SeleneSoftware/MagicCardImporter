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

Nothing to configure

## Usage

Running the base command ```magic:import``` will just output a list of all available sets on Scryfall.  Issuing a set code after the command, ```magic:import roe``` (Rise of the Eldrazi), will pull all the card data and create the product information for each card in the set.

## Issues

Currently, there are a few things that need some work:
 - There are custom attributes for the cards, but I want to create an attribute set and include them all.  Currently, these custom attributes will get attached to all product in the store.
 - Custom Attributes are not populating properly when the product is created.  This needs to be fixed.
 - The category is created when the import is running, but it won't put it under the "Magic: the Gathering" category.  Need to figure out how to move it properly.
 - Images.  I haven't gotten around to it yet.

If you think you can solve one of these issues, pull requests will be welcomed at https://github.com/SeleneSoftware/MagicCardImporter



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

