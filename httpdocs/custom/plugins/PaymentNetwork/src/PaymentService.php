<?php declare(strict_types=1);

namespace P3\PaymentNetwork;

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentProcessException;
use Shopware\Core\Checkout\Payment\Exception\CustomerCanceledAsyncPaymentException;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class PaymentService implements AsynchronousPaymentHandlerInterface
{
    /**
     * @var OrderTransactionStateHandler
     */
    private $transactionStateHandler;
    /**
     * @var UrlGeneratorInterface
     */
    private UrlGeneratorInterface $router;
    /**
     * @var Session
     */
    private Session $session;

    public function __construct(OrderTransactionStateHandler $transactionStateHandler, UrlGeneratorInterface $router, Session $session)
    {
        $this->transactionStateHandler = $transactionStateHandler;
        $this->router = $router;
        $this->session = $session;
    }

    /**
     * @throws AsyncPaymentProcessException
     */
    public function pay(
        AsyncPaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext
    ): RedirectResponse {
        $url = $this->router->generate("frontend.checkout.p3_order_complete", [
            'orderId' => $transaction->getOrder()->getId(),
            'paymentMethodId' => $transaction->getOrderTransaction()->getPaymentMethodId(),
            'id' => $transaction->getOrderTransaction()->getId(),
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        $this->session->set('p3_process_returnUrl', $url);
        $this->session->set('p3_process_orderId', $transaction->getOrder()->getId());
        $link = $this->router->generate('frontend.checkout.p3_order_process');

        $context = $salesChannelContext->getContext();

        $this->transactionStateHandler->process($transaction->getOrderTransaction()->getId(), $context);

        // Redirect to external gateway
        return new RedirectResponse($link);
    }

    /**
     * @throws CustomerCanceledAsyncPaymentException
     */
    public function finalize(
        AsyncPaymentTransactionStruct $transaction,
        Request $request,
        SalesChannelContext $salesChannelContext
    ): void {
        $transactionId = $transaction->getOrderTransaction()->getId();

        // Cancelled payment?
        if ($request->query->getBoolean('cancel')) {
            throw new CustomerCanceledAsyncPaymentException(
                $transactionId,
                'Customer canceled the payment on the PayPal page'
            );
        }

        $paymentState = $request->query->getAlpha('status');

        $context = $salesChannelContext->getContext();
        if ($paymentState === 'completed') {
            // Payment completed, set transaction status to "paid"
            $this->transactionStateHandler->paid($transaction->getOrderTransaction()->getId(), $context);
        } else {
            // Payment not completed, set transaction status to "open"
            $this->transactionStateHandler->reopen($transaction->getOrderTransaction()->getId(), $context);
        }
    }
}
