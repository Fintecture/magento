# Fintecture module for Magento 2.4 & 2.3

[![Latest Stable Version](http://poser.pugx.org/fintecture/payment/v)](https://packagist.org/packages/fintecture/payment) [![Total Downloads](http://poser.pugx.org/fintecture/payment/downloads)](https://packagist.org/packages/fintecture/payment) [![Monthly Downloads](http://poser.pugx.org/fintecture/payment/d/monthly)](https://packagist.org/packages/fintecture/payment) [![License](http://poser.pugx.org/fintecture/payment/license)](https://packagist.org/packages/fintecture/payment) [![PHP Version Require](http://poser.pugx.org/fintecture/payment/require/php)](https://packagist.org/packages/fintecture/payment)

Fintecture is a Fintech that has a payment solution via bank transfer available at https://www.fintecture.com.

You can take a look at our API here: https://docs.fintecture.com

## Requirements

- Magento 2.3.X & Magento 2.4.X
- PHP >= 7.2

More information on [Magento documentation](https://devdocs.magento.com/guides/v2.4/install-gde/system-requirements.html).

## Installation

You can install our plugin with Composer:

`composer require fintecture/payment`

### Optional dependencies

#### Payment by QR Code when using "Login as Customer" feature

*Reserved to a Magento 2.4.1+ instance with the magento/module-login-as-customer module already installed*

To enable it, you must install this dependency:

`composer require chillerlan/php-qrcode`

## Activation

- Enable Fintecture Payment module: `php bin/magento module:enable Fintecture_Payment`
- Check Module status: `php bin/magento module:status Fintecture_Payment`
- Apply upgrade: `php bin/magento setup:upgrade`
- Deploy static content: `php bin/magento setup:static-content:deploy -f`
- Compile catalog: `php bin/magento setup:di:compile`
- Clean the cache: `php bin/magento cache:clean` or go to System > Tools > Cache Management and click Flush Static Files Cache.

## Configuration

Go to Stores > Configuration > Sales > Payment methods.

- Select environment (sandbox/production)
- Fill APP ID, APP secret and private key based on the selected environment (https://console.fintecture.com)
- Test your connection (if everything is ok you should have a green message)
- Don't forget to enable the payment method unless it won't be displayed in the front end

## Other Magento versions

- Magento 2.2 : Please contact us for more information.
- Magento 2.1 : Please contact us for more information.