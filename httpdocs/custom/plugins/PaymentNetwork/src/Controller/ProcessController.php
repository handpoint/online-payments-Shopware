<?php


namespace P3\PaymentNetwork\Controller;

use P3\PaymentNetwork\Gateway;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\Token\TokenFactoryInterfaceV2;
use Shopware\Core\Checkout\Payment\Cart\Token\TokenStruct;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Class ProcessController
 * @package P3\PaymentNetwork\Controller
 *
 * @RouteScope(scopes={"storefront"})
 */
class ProcessController extends StorefrontController
{
    /**
     * @var TokenFactoryInterfaceV2
     */
    private TokenFactoryInterfaceV2 $factory;
    /**
     * @var SystemConfigService
     */
    private SystemConfigService $systemConfigService;

    public function __construct(TokenFactoryInterfaceV2 $factory, SystemConfigService $systemConfigService)
    {
        $this->factory = $factory;
        $this->systemConfigService = $systemConfigService;
    }

    /**
     * @param Request $request
     * @param Context $context
     * @return \Symfony\Component\HttpFoundation\Response
     *
     * @Route("/p3/order/process", name="frontend.checkout.p3_order_process", options={"seo"="false"}, methods={"GET"})
     */
    public function process(Request $request, Context $context)
    {
        $debug = $this->systemConfigService->get('PaymentPlugin.config.debug');

        $obj = $this->container->get('order.repository');
        $res = $obj->search((new Criteria())->addFilter(new EqualsFilter('id', $request->getSession()->get('p3_process_orderId'))), $context);

        /** @var OrderEntity $order */
        $order = $res->first();

        $currency = $this->container->get('currency.repository')->search((new Criteria())->addFilter(new EqualsFilter('id', $order->getCurrencyId())), $context)->first();
        $address = $this->container->get('order_address.repository')->search((new Criteria())->addFilter(new EqualsFilter('id', $order->getBillingAddressId())), $context)->first();
        $country = $this->container->get('country.repository')->search((new Criteria())->addFilter(new EqualsFilter('id', $address->getCountryId())), $context)->first();
        $state = $this->container->get('country_state.repository')->search((new Criteria())->addFilter(new EqualsFilter('id', $address->getCountryStateId())), $context)->first();

        $customer = $order->getOrderCustomer();

        $billingAddress = $address->getStreet().PHP_EOL;
        $billingAddress .= $address->getZipcode(). ' '.$address->getCity().PHP_EOL;
        if ($state) {
            $billingAddress .= $state->getName().PHP_EOL;
        }

        if ($country) {
            $billingAddress .= $country->getName().PHP_EOL;
        }

        $req = array(
            'action' => 'SALE',
            'merchantID' => 100856,
            'amount' => (int) (round($order->getPositionPrice(), 2) * 100),
            'currencyCode' => $currency ? $currency->getIsoCode() : $this->systemConfigService->get('PaymentPlugin.config.currency'),
            'countryCode' => $country ? $country->getIso3() : $this->systemConfigService->get('PaymentPlugin.config.countryCode'),
            'transactionUnique' => $order->getId(),
            'type' => 1,
            'orderRef' => $order->getOrderNumber(),
            'customerName' => $customer->getFirstName(). ' '. $customer->getLastName(),
            'customerEmail' => $customer->getEmail(),
            'customerAddress' => $billingAddress,
            'customerPostCode' => $address->getZipcode(),
            'redirectURL' => $request->getSession()->get('p3_process_returnUrl').($debug ? '&XDEBUG_SESSION_START=something': '')
        );

        $options = [];

        $integrationType = $this->systemConfigService->get('PaymentPlugin.config.integrationType');

        if ('modal' === $integrationType) {
            // for hosted modal integration we just overwrite the url
            $options['hostedUrl'] = 'https://gateway.cardstream.com/hosted/modal/';
        }

        $gateway = new Gateway($options);

        return $this->renderStorefront(
            '@PaymentPlugin/storefront/hosted.html.twig',
            [
                'form' => $gateway->hostedRequest($req)
            ]
        );
    }

    /**
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     *
     * @Route("/p3/order/complete", name="frontend.checkout.p3_order_complete", options={"seo"="false"}, methods={"POST", "GET"}, defaults={"csrf_protected": false})
     */
    public function afterPay(Request $request) {
        $orderId = $request->get('orderId');
        $paymentMethodId = $request->get('paymentMethodId');
        $transactionId = $request->get('id');

        $finishUrl = $this->generateUrl('frontend.checkout.finish.page', ['orderId' => $orderId]);
        $errorUrl = $this->generateUrl('frontend.account.edit-order.page', ['orderId' => $orderId]);

        $tokenStruct = new TokenStruct(
            null,
            null,
            $paymentMethodId,
            $transactionId,
            $finishUrl,
            null,
            $errorUrl
        );

        $token = $this->factory->generateToken($tokenStruct);

        $parameter = ['_sw_payment_token' => $token, 'XDEBUG_SESSION_START' => 'something', 'status' => 'completed'];

        $returnUrl = $this->generateUrl('payment.finalize.transaction', $parameter, UrlGeneratorInterface::ABSOLUTE_URL);

        return $this->redirect($returnUrl);
    }
}