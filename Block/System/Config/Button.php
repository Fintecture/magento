<?php

declare(strict_types=1);

namespace Fintecture\Payment\Block\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Backend\Block\Widget\Button as WidgetButton;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Store\Model\ScopeInterface;

class Button extends Field
{
    /** @var string */
    protected $_template = 'Fintecture_Payment::system/config/button.phtml';

    /** @var Http */
    protected $request;

    public function __construct(
        Context $context,
        Http $request,
        array $data = []
    ) {
        $this->request = $request;
        parent::__construct($context, $data);
    }

    public function render(AbstractElement $element)
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();

        return parent::render($element);
    }

    public function getCustomUrl(): string
    {
        $scope = $this->getScope();

        return $this->getUrl('fintecture/settings/connectiontest', [
            'isAjax' => true,
            'form_key' => $this->getFormKey(),
            'scope' => $scope['scope'],
            'scopeId' => $scope['scopeId']
        ]);
    }

    public function getButtonHtml()
    {
        /** @var WidgetButton $button */
        $button = $this->getLayout()->createBlock(WidgetButton::class);
        $button->setData([
            'id' => 'connection-test',
            'label' => __('Test Connection')
        ]);

        return $button->toHtml();
    }

    protected function _getElementHtml(AbstractElement $element)
    {
        return $this->_toHtml();
    }

    protected function getScope(): array
    {
        if ($this->request->getParam('store')) {
            return [
                'scope' => ScopeInterface::SCOPE_STORE,
                'scopeId' => (int) $this->request->getParam('store')
            ];
        } elseif ($this->request->getParam('website')) {
            return [
                'scope' => ScopeInterface::SCOPE_WEBSITE,
                'scopeId' => (int) $this->request->getParam('website')
            ];
        }

        return [
            'scope' => ScopeInterface::SCOPE_STORE,
            'scopeId' => 0
        ];
    }
}
